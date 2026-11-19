<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
use App\Models\MedicalCertificate;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientConsent;
use App\Models\Prescription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Rights of the patient over personal data: consents, a copy of the record (access and
 * portability) and anonymisation. Medical content is kept for the legal retention period;
 * only the identity and contact data are erased.
 */
final class PatientRights
{
    /** @return list<array{type: string, label: string, granted: bool, changed_at: ?string}> */
    public function consents(Patient $patient): array
    {
        $stored = PatientConsent::query()->where('patient_id', $patient->id)->get()->keyBy('type');

        return collect(PatientConsent::TYPES)->map(function (string $label, string $type) use ($stored) {
            $consent = $stored->get($type);

            return [
                'type' => $type,
                'label' => $label,
                'granted' => (bool) $consent?->granted,
                'changed_at' => $consent?->changed_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @param array<string, bool> $values */
    public function updateConsents(Patient $patient, array $values, ?int $userId): void
    {
        DB::transaction(function () use ($patient, $values, $userId) {
            foreach ($values as $type => $granted) {
                PatientConsent::query()->updateOrCreate(
                    ['patient_id' => $patient->id, 'type' => $type],
                    [
                        'clinic_id' => $patient->clinic_id,
                        'granted' => (bool) $granted,
                        'recorded_by' => $userId,
                        'changed_at' => now(),
                    ],
                );
            }
        });
    }

    /** Everything the clinic holds about the patient, in a readable structure. */
    public function export(Patient $patient): array
    {
        $patient->loadMissing(['allergies', 'medicalHistory', 'emergencyContacts']);
        $consultationIds = Consultation::query()->where('patient_id', $patient->id)->pluck('id');

        return [
            'exported_at' => now()->toIso8601String(),
            'clinic' => $patient->clinic()->value('name'),
            'identity' => $patient->only([
                'patient_unique_id', 'first_name', 'last_name', 'date_of_birth', 'gender', 'phone', 'email',
                'national_id', 'marital_status', 'profession', 'address', 'city', 'blood_group',
            ]),
            'consents' => $this->consents($patient),
            'allergies' => $patient->allergies->map->only(['allergen', 'severity', 'notes'])->values(),
            'medical_history' => $patient->medicalHistory->map->only(['condition', 'start_date', 'end_date', 'notes'])->values(),
            'emergency_contacts' => $patient->emergencyContacts->map->only(['emergency_contact_name', 'emergency_contact_phone', 'relationship'])->values(),
            'appointments' => Appointment::query()->with('doctor:id,name')->where('patient_id', $patient->id)->orderBy('appointment_datetime')->get()
                ->map(fn (Appointment $item) => [
                    'date' => $item->appointment_datetime?->toIso8601String(),
                    'status' => $item->status,
                    'reason' => $item->reason,
                    'doctor' => $item->doctor?->name,
                ])->values(),
            'consultations' => Consultation::query()->with(['doctor:id,name'])->where('patient_id', $patient->id)->orderBy('consultation_datetime')->get()
                ->map(fn (Consultation $item) => [
                    'date' => $item->consultation_datetime?->toIso8601String(),
                    'status' => $item->status,
                    'doctor' => $item->doctor?->name,
                ])->values(),
            'prescriptions' => Prescription::query()->with(['doctor:id,name', 'items'])->whereIn('consultation_id', $consultationIds)->get()
                ->map(fn (Prescription $item) => [
                    'date' => $item->prescribed_at?->toIso8601String(),
                    'status' => $item->status,
                    'doctor' => $item->doctor?->name,
                    'items' => $item->items->map->only(['dosage_prescribed', 'frequency', 'duration_days', 'quantity', 'special_instructions'])->values(),
                ])->values(),
            'lab_requests' => LabRequest::query()->with(['exams:id,name', 'doctor:id,name'])->where('patient_id', $patient->id)->get()
                ->map(fn (LabRequest $item) => [
                    'requested_at' => $item->requested_at?->toIso8601String(),
                    'status' => $item->status,
                    'doctor' => $item->doctor?->name,
                    'exams' => $item->exams->pluck('name')->values(),
                ])->values(),
            'imaging_requests' => ImagingRequest::query()->with(['exam:id,name', 'doctor:id,name'])->where('patient_id', $patient->id)->get()
                ->map(fn (ImagingRequest $item) => [
                    'requested_at' => $item->requested_at?->toIso8601String(),
                    'status' => $item->status,
                    'doctor' => $item->doctor?->name,
                    'exam' => $item->exam?->name,
                ])->values(),
            'invoices' => Invoice::query()->where('patient_id', $patient->id)->orderBy('issued_at')->get()
                ->map(fn (Invoice $item) => [
                    'number' => $item->invoice_number,
                    'status' => $item->status,
                    'total' => $item->total_amount,
                    'issued_at' => $item->issued_at?->toIso8601String(),
                    'paid_at' => $item->paid_at?->toIso8601String(),
                ])->values(),
            'certificates' => MedicalCertificate::query()->with('doctor:id,name')->where('patient_id', $patient->id)->get()
                ->map(fn (MedicalCertificate $item) => [
                    'number' => $item->number,
                    'type' => $item->type,
                    'start_date' => $item->start_date?->toDateString(),
                    'end_date' => $item->end_date?->toDateString(),
                    'issued_at' => $item->issued_at?->toIso8601String(),
                    'cancelled_at' => $item->cancelled_at?->toIso8601String(),
                    'doctor' => $item->doctor?->name,
                ])->values(),
        ];
    }

    /**
     * Erases identity and contact data. The record keeps its number and its medical content so
     * that care history and legal retention are preserved. The caller must check that no patient
     * account is linked to the record.
     */
    public function anonymize(Patient $patient): void
    {
        if ($patient->anonymized_at !== null) {
            throw ValidationException::withMessages(['patient' => 'Ce dossier est déjà anonymisé.']);
        }

        DB::transaction(function () use ($patient) {
            if ($patient->photo_path) {
                Storage::disk('local')->delete($patient->photo_path);
            }
            $patient->emergencyContacts()->delete();
            $patient->forceFill([
                'first_name' => 'Anonyme',
                'last_name' => 'ANONYME',
                'phone' => null,
                'email' => null,
                'date_of_birth' => null,
                'national_id' => null,
                'marital_status' => null,
                'profession' => null,
                'address' => null,
                'city' => null,
                'blood_group' => null,
                'photo_url' => null,
                'photo_path' => null,
                'metadata' => null,
                'anonymized_at' => Carbon::now(),
            ])->save();
        });
    }
}
