<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Medication;
use App\Models\Prescription;
use App\Support\DrugInteractionChecker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PrescriptionController extends Controller
{
    /** Days during which a signed prescription can still be dispensed. */
    public const PHARMACY_VALIDITY_DAYS = 30;

    public function medications(Request $request)
    {
        if ($request->user()->role?->name === 'PATIENT') {
            return response()->json(['message' => 'Les patients ne voient que les médicaments de leurs ordonnances.'], 403);
        }

        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['data' => []]);
        }

        $medications = Medication::query()
            ->where('clinic_id', $clinicId)
            ->orderBy('name')
            ->get(['id', 'name', 'dosage', 'form', 'manufacturer']);

        return response()->json(['data' => $medications]);
    }




    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);

        return response()->json(Prescription::query()
            ->with(['consultation.patient:id,first_name,last_name,patient_unique_id', 'doctor:id,name', 'items.medication:id,name,dosage,form'])
            ->when($clinicId !== null, fn ($query) => $query->where('clinic_id', $clinicId))
            ->when($request->user()->role?->name === 'DOCTOR', fn ($query) => $query->where('doctor_id', $request->user()->id))
            // Pharmacy: only the active prescriptions awaiting dispensing, never the history.
            ->when($request->user()->role?->name === 'PHARMACIST', fn ($query) => $query
                ->where('status', 'SIGNED')
                ->where('prescribed_at', '>=', now()->subDays(self::PHARMACY_VALIDITY_DAYS)))
            ->when($request->user()->role?->name === 'PATIENT', function ($query) use ($request) {
                $query->whereHas('consultation', fn ($consultations) => $consultations
                    ->where('patient_id', $request->user()->patient?->id ?? 0))
                    ->whereIn('status', ['SIGNED', 'DISPENSED']);
            })
            ->latest('prescribed_at')
            ->paginate(30));
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Choisissez une clinique avant de rédiger des ordonnances.'], 422);
        }

        $validated = $request->validate([
            'consultation_id' => ['required', 'integer', Rule::exists('consultations', 'id')->where('clinic_id', $clinicId)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.medication_id' => ['required', 'integer', Rule::exists('medications', 'id')->where('clinic_id', $clinicId)],
            'items.*.dosage_prescribed' => ['required', 'string', 'max:255'],
            'items.*.frequency' => ['required', 'string', 'max:255'],
            'items.*.duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.special_instructions' => ['nullable', 'string', 'max:1000'],
            'acknowledge_interactions' => ['sometimes', 'boolean'],
        ]);

        $consultation = Consultation::query()->whereKey($validated['consultation_id'])->firstOrFail();
        if ($request->user()->role?->name === 'DOCTOR' && (int) $consultation->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Vous ne pouvez prescrire que pour vos consultations.'], 403);
        }
        if ($consultation->status === 'SIGNED') {
            return response()->json(['message' => 'A signed consultation cannot receive new prescriptions.'], 422);
        }

        $interactions = DrugInteractionChecker::forPatient(
            (int) $consultation->patient_id,
            array_column($validated['items'], 'medication_id'),
        );
        if ($interactions->contains('severity', 'HIGH') && !($validated['acknowledge_interactions'] ?? false)) {
            return response()->json([
                'message' => 'Interaction médicamenteuse grave détectée. Confirmez explicitement pour prescrire malgré tout.',
                'code' => 'DRUG_INTERACTION',
                'interactions' => $interactions,
            ], 422);
        }
        unset($validated['acknowledge_interactions']);

        $prescription = DB::transaction(function () use ($validated, $clinicId, $consultation): Prescription {
            $prescription = Prescription::create([
                'clinic_id' => $clinicId,
                'consultation_id' => $consultation->id,
                'doctor_id' => $consultation->doctor_id,
                'prescribed_at' => now(),
                'status' => 'DRAFT',
                'notes' => $validated['notes'] ?? null,
            ]);
            $prescription->items()->createMany($validated['items']);

            return $prescription;
        });

        return response()->json([
            'data' => $prescription->load(['consultation.patient', 'doctor:id,name', 'items.medication']),
            'interactions' => $interactions,
        ], 201);
    }

    public function checkInteractions(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['data' => []]);
        }

        $validated = $request->validate([
            'consultation_id' => ['required', 'integer', Rule::exists('consultations', 'id')->where('clinic_id', $clinicId)],
            'medication_ids' => ['required', 'array', 'min:1', 'max:30'],
            'medication_ids.*' => ['integer', Rule::exists('medications', 'id')->where('clinic_id', $clinicId)],
        ]);
        $consultation = Consultation::query()->whereKey($validated['consultation_id'])->firstOrFail();

        return response()->json([
            'data' => DrugInteractionChecker::forPatient((int) $consultation->patient_id, $validated['medication_ids']),
        ]);
    }

    public function show(Request $request, Prescription $prescription)
    {
        if (!$this->canAccess($request, $prescription)) {
            return response()->json(['message' => 'Ordonnance introuvable.'], 404);
        }

        $prescription->load(['consultation.patient', 'doctor:id,name', 'doctor.doctorProfile:id,user_id,license_number', 'items.medication', 'clinic:id,name,city,country,admin_phone,admin_email']);
        // Verification code printed on the prescription so a pharmacy can check it was not altered.
        $verification = \App\Services\Prescriptions\PrescriptionPdf::verificationCode($prescription);

        return response()->json(['data' => [...$prescription->toArray(), 'verification_code' => $verification]]);
    }

    /** The prescription as a PDF, for the patient and the pharmacy. Drafts are not printable. */
    public function pdf(Request $request, Prescription $prescription)
    {
        if (!$this->canAccess($request, $prescription)) {
            return response()->json(['message' => 'Ordonnance introuvable.'], 404);
        }
        if (!in_array($prescription->status, ['SIGNED', 'DISPENSED'], true)) {
            return response()->json(['message' => 'Seule une ordonnance signée peut être imprimée.'], 422);
        }

        return response(\App\Services\Prescriptions\PrescriptionPdf::render($prescription), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="ORD-'.str_pad((string) $prescription->id, 6, '0', STR_PAD_LEFT).'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function sign(Request $request, Prescription $prescription)
    {
        if (!$this->canAccess($request, $prescription)) {
            return response()->json(['message' => 'Ordonnance introuvable.'], 404);
        }
        if ($request->user()->role?->name !== 'DOCTOR' || (int) $prescription->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Seul le médecin prescripteur peut signer cette ordonnance.'], 403);
        }
        if ($prescription->status !== 'DRAFT') {
            return response()->json(['message' => 'Seule une ordonnance brouillon peut être signée.'], 422);
        }
        // A signed prescription is a legal document: the prescriber's registration number must be on it.
        $licence = $request->user()->doctorProfile?->license_number;
        if (blank($licence)) {
            return response()->json([
                'message' => 'Renseignez votre numéro d’ordre dans vos paramètres avant de signer une ordonnance.',
                'code' => 'LICENCE_REQUIRED',
            ], 422);
        }

        $prescription->update(['status' => 'SIGNED']);

        return response()->json(['data' => $prescription->fresh()->load(['consultation.patient', 'doctor:id,name', 'items.medication'])]);
    }


    public function update(Request $request, Prescription $prescription)
    {
        if (!$this->canAccess($request, $prescription)) {
            return response()->json(['message' => 'Ordonnance introuvable.'], 404);
        }
        if ($prescription->status !== 'DRAFT') {
            return response()->json(['message' => 'Seule une ordonnance brouillon peut être modifiée.'], 422);
        }
        if ($request->user()->role?->name !== 'DOCTOR' || (int) $prescription->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Seul le médecin prescripteur peut modifier cette ordonnance.'], 403);
        }

        $validated = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $prescription->update($validated);

        return response()->json(['data' => $prescription->fresh()->load(['consultation.patient', 'doctor:id,name', 'items.medication'])]);
    }

    public function destroy(Request $request, Prescription $prescription)
    {
        if (!$this->canAccess($request, $prescription)) {
            return response()->json(['message' => 'Ordonnance introuvable.'], 404);
        }
        if ($prescription->status !== 'DRAFT') {
            return response()->json(['message' => 'Seule une ordonnance brouillon peut être supprimée.'], 422);
        }

        $prescription->delete();

        return response()->noContent();
    }

    private function canAccess(Request $request, Prescription $prescription): bool
    {
        $clinicId = $this->clinicId($request);
        $isPatientOutsideOwnCase = $request->user()->role?->name === 'PATIENT'
            && ((int) $prescription->consultation()->value('patient_id') !== (int) ($request->user()->patient?->id ?? 0)
                || !in_array($prescription->status, ['SIGNED', 'DISPENSED'], true));

        // A pharmacist sees one prescription only: an active one, not the rest of the history.
        $isPharmacyOutsideActive = $request->user()->role?->name === 'PHARMACIST'
            && ($prescription->status !== 'SIGNED' || $prescription->prescribed_at?->lt(now()->subDays(self::PHARMACY_VALIDITY_DAYS)));

        return ($clinicId === null || (int) $prescription->clinic_id === $clinicId)
            && !$isPatientOutsideOwnCase
            && !$isPharmacyOutsideActive
            && ($request->user()->role?->name !== 'DOCTOR' || (int) $prescription->doctor_id === (int) $request->user()->id);
    }

    private function clinicId(Request $request): ?int
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return $clinicId === null ? null : (int) $clinicId;
    }
}
