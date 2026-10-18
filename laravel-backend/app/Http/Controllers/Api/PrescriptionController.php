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
    public function medications(Request $request)
    {
        if ($request->user()->role?->name === 'PATIENT') {
            return response()->json(['message' => 'Patients can only view medicines included in their prescriptions.'], 403);
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
            return response()->json(['message' => 'Select a clinic before writing prescriptions.'], 422);
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
            return response()->json(['message' => 'You can only prescribe for your own consultations.'], 403);
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
            return response()->json(['message' => 'Prescription not found.'], 404);
        }

        $prescription->load(['consultation.patient', 'doctor:id,name', 'items.medication', 'clinic:id,name,city,country,admin_phone,admin_email']);
        // Verification code printed on the prescription so a pharmacy can check it was not altered.
        $verification = in_array($prescription->status, ['SIGNED', 'DISPENSED'], true)
            ? strtoupper(substr(hash_hmac('sha256', implode('|', [
                $prescription->id,
                $prescription->clinic_id,
                $prescription->doctor_id,
                $prescription->prescribed_at?->toIso8601String(),
                $prescription->items->map(fn ($item) => "{$item->medication_id}:{$item->dosage_prescribed}:{$item->frequency}:{$item->duration_days}:{$item->quantity}")->implode(','),
            ]), (string) config('app.key')), 0, 16))
            : null;

        return response()->json(['data' => [...$prescription->toArray(), 'verification_code' => $verification]]);
    }

    public function sign(Request $request, Prescription $prescription)
    {
        if (!$this->canAccess($request, $prescription)) {
            return response()->json(['message' => 'Prescription not found.'], 404);
        }
        if ($request->user()->role?->name !== 'DOCTOR' || (int) $prescription->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Only the prescribing doctor can sign this prescription.'], 403);
        }
        if ($prescription->status !== 'DRAFT') {
            return response()->json(['message' => 'Only draft prescriptions can be signed.'], 422);
        }

        $prescription->update(['status' => 'SIGNED']);

        return response()->json(['data' => $prescription->fresh()->load(['consultation.patient', 'doctor:id,name', 'items.medication'])]);
    }


    public function update(Request $request, Prescription $prescription)
    {
        if (!$this->canAccess($request, $prescription)) {
            return response()->json(['message' => 'Prescription not found.'], 404);
        }
        if ($prescription->status !== 'DRAFT') {
            return response()->json(['message' => 'Only draft prescriptions can be modified.'], 422);
        }
        if ($request->user()->role?->name !== 'DOCTOR' || (int) $prescription->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Only the prescribing doctor can modify this prescription.'], 403);
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
            return response()->json(['message' => 'Prescription not found.'], 404);
        }
        if ($prescription->status !== 'DRAFT') {
            return response()->json(['message' => 'Only draft prescriptions can be deleted.'], 422);
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

        return ($clinicId === null || (int) $prescription->clinic_id === $clinicId)
            && !$isPatientOutsideOwnCase
            && ($request->user()->role?->name !== 'DOCTOR' || (int) $prescription->doctor_id === (int) $request->user()->id);
    }

    private function clinicId(Request $request): ?int
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return $clinicId === null ? null : (int) $clinicId;
    }
}
