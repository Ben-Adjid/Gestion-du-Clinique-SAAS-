<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\Bed;
use App\Models\Patient;
use App\Models\PatientSpecialtyRecord;
use App\Models\Room;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Hospital stays: who is admitted where, moves between beds, discharge with a summary.
 * The clinical file of a stay (vital signs, prescriptions) is reserved to doctors and nurses:
 * administration sees the bed and the identity, never the medical content.
 */
class AdmissionController extends Controller
{
    /** Roles allowed to open the clinical file of a stay. */
    private const CLINICAL_ROLES = ['DOCTOR', 'NURSE'];

    /** Active stays of the clinic, optionally filtered by service. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['service' => ['sometimes', 'nullable', 'in:'.implode(',', Room::SERVICES)]]);

        $stays = Admission::query()
            ->whereIn('status', Admission::ACTIVE)
            ->with(['patient:id,first_name,last_name,patient_unique_id', 'bed:id,code', 'room:id,name,service', 'doctor:id,name'])
            ->when($validated['service'] ?? null, fn ($query, $service) => $query->whereHas('room', fn ($rooms) => $rooms->where('service', $service)))
            ->orderBy('admitted_at')
            ->get();

        return response()->json(['data' => $stays->map(fn (Admission $stay) => $this->summary($stay, $request))->values()]);
    }

    /**
     * Search by name, file number (HARIRI-AAAA-NNNNNN) or service. Each patient found says whether
     * he is hospitalised, and where; a patient who is not is said so clearly.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
            'service' => ['sometimes', 'nullable', 'in:'.implode(',', Room::SERVICES)],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:40', Rule::exists('specialties', 'code')],
        ]);
        $term = trim($validated['q']);
        $specialty = isset($validated['specialty']) && $validated['specialty'] !== '' ? Specialty::query()->where('code', $validated['specialty'])->first() : null;

        // A specialty filter finds patients with a record of that specialty, or hospitalised in its service or under its doctors.
        $patients = Patient::query()
            ->where(fn ($query) => $query
                ->where('patient_unique_id', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%"))
            ->when($specialty, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereIn('id', PatientSpecialtyRecord::query()->where('specialty_id', $specialty->id)->select('patient_id'))
                ->orWhereIn('id', Admission::query()->whereIn('status', Admission::ACTIVE)->where(fn ($stay) => $stay
                    ->whereHas('room', fn ($rooms) => $rooms->where('service', $specialty->service))
                    ->orWhereHas('doctor.doctorProfile', fn ($profiles) => $profiles->where('specialty_id', $specialty->id)))
                    ->select('patient_id'))))
            ->orderBy('last_name')->limit(20)->get(['id', 'first_name', 'last_name', 'patient_unique_id']);

        $results = $patients->map(function (Patient $patient) use ($request, $validated) {
            $stay = Admission::query()->whereIn('status', Admission::ACTIVE)->where('patient_id', $patient->id)
                ->with(['bed:id,code', 'room:id,name,service', 'doctor:id,name'])->latest('admitted_at')->first();
            if (isset($validated['service']) && (!$stay || $stay->room?->service !== $validated['service'])) {
                return null;
            }

            return [
                'patient' => ['id' => $patient->id, 'name' => trim($patient->first_name.' '.$patient->last_name), 'patient_unique_id' => $patient->patient_unique_id],
                'hospitalised' => $stay !== null,
                'message' => $stay ? null : 'Ce patient n’est pas hospitalisé.',
                'stay' => $stay ? $this->summary($stay, $request) : null,
            ];
        })->filter()->values();

        return response()->json(['data' => $results]);
    }

    /** Clinical file of a stay: the stay, its moves, and the medical record it refers to. */
    public function show(Request $request, Admission $admission): JsonResponse
    {
        if (!in_array($request->user()->role?->name, self::CLINICAL_ROLES, true)) {
            return response()->json(['message' => 'Le dossier de soins d’une hospitalisation est réservé au personnel soignant.'], 403);
        }

        $admission->load(['patient:id,first_name,last_name,patient_unique_id,date_of_birth', 'bed:id,code', 'room:id,name,service', 'doctor:id,name', 'transfers.fromBed:id,code', 'transfers.toBed:id,code']);
        $patientId = $admission->patient_id;

        return response()->json(['data' => [
            ...$this->summary($admission, $request),
            'discharge_summary' => $admission->discharge_summary,
            'transfers' => $admission->transfers->map(fn (AdmissionTransfer $move) => [
                'from' => $move->fromBed?->code, 'to' => $move->toBed?->code, 'reason' => $move->reason,
                'at' => $move->transferred_at?->toIso8601String(),
            ])->values(),
            'vitals' => \App\Models\VitalSign::query()->where('patient_id', $patientId)->latest('recorded_at')->limit(10)->get(),
            'prescriptions' => \App\Models\Prescription::query()->whereHas('consultation', fn ($q) => $q->where('patient_id', $patientId))
                ->latest('prescribed_at')->limit(5)->get(['id', 'status', 'prescribed_at']),
            'invoices' => \App\Models\Invoice::query()->where('patient_id', $patientId)->latest('issued_at')->limit(5)->get(['id', 'invoice_number', 'status', 'total_amount']),
        ]]);
    }

    /** Moves an admitted patient from his bed to a free bed of the same clinic. */
    public function transfer(Request $request, Admission $admission): JsonResponse
    {
        if (!$admission->isActive()) {
            throw ValidationException::withMessages(['admission' => 'Ce séjour est terminé : le patient n’est plus hospitalisé.']);
        }
        $validated = $request->validate([
            'bed_id' => ['required', 'integer', Rule::exists('beds', 'id')->where('clinic_id', $admission->clinic_id)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($admission, $validated, $request) {
            $target = Bed::query()->lockForUpdate()->findOrFail($validated['bed_id']);
            if ($target->id === $admission->bed_id) {
                throw ValidationException::withMessages(['bed_id' => 'Le patient est déjà dans ce lit.']);
            }
            if ($target->status !== 'AVAILABLE') {
                throw ValidationException::withMessages(['bed_id' => 'Ce lit n’est pas libre.']);
            }

            $origin = $admission->bed_id ? Bed::query()->find($admission->bed_id) : null;
            $now = Carbon::now();
            // The vacated bed is disinfected, the new one is occupied by the same stay.
            $origin?->update(['status' => 'CLEANING', 'patient_id' => null, 'admitted_at' => null, 'updated_by' => $request->user()->id]);
            $target->update(['status' => 'OCCUPIED', 'patient_id' => $admission->patient_id, 'admitted_at' => $now, 'updated_by' => $request->user()->id]);

            AdmissionTransfer::create([
                'clinic_id' => $admission->clinic_id, 'admission_id' => $admission->id, 'from_bed_id' => $origin?->id,
                'to_bed_id' => $target->id, 'reason' => $validated['reason'] ?? null,
                'transferred_by' => $request->user()->id, 'transferred_at' => $now,
            ]);
            $admission->update(['bed_id' => $target->id, 'room_id' => $target->room_id, 'status' => 'TRANSFERE']);
        });

        return response()->json(['data' => $this->summary($admission->fresh()->load(['bed:id,code', 'room:id,name,service', 'doctor:id,name', 'patient:id,first_name,last_name,patient_unique_id']), $request), 'message' => 'Patient transféré.']);
    }

    /** Discharge: closes the stay with a summary, and sends the bed to disinfection. */
    public function discharge(Request $request, Admission $admission): JsonResponse
    {
        if (!in_array($request->user()->role?->name, self::CLINICAL_ROLES, true)) {
            return response()->json(['message' => 'Seul le personnel soignant peut signer la sortie d’un patient.'], 403);
        }
        if (!$admission->isActive()) {
            throw ValidationException::withMessages(['admission' => 'Ce séjour est déjà terminé.']);
        }
        $validated = $request->validate([
            'discharge_summary' => ['required', 'string', 'min:20', 'max:5000'],
        ], ['discharge_summary.required' => 'Rédigez le compte rendu de sortie (au moins 20 caractères).', 'discharge_summary.min' => 'Le compte rendu de sortie est trop court (20 caractères minimum).']);

        DB::transaction(function () use ($admission, $validated, $request) {
            $bed = $admission->bed_id ? Bed::query()->lockForUpdate()->find($admission->bed_id) : null;
            $bed?->update(['status' => 'CLEANING', 'patient_id' => null, 'admitted_at' => null, 'updated_by' => $request->user()->id]);
            $admission->update(['status' => 'SORTI', 'discharged_at' => Carbon::now(), 'discharge_summary' => $validated['discharge_summary']]);
        });

        return response()->json(['data' => $this->summary($admission->fresh()->load(['bed:id,code', 'room:id,name,service', 'doctor:id,name', 'patient:id,first_name,last_name,patient_unique_id']), $request), 'message' => 'Sortie enregistrée. Le lit est en désinfection.']);
    }

    /** Non-medical summary of a stay: identity, place and responsible doctor. Reason only for clinical roles. */
    private function summary(Admission $stay, Request $request): array
    {
        $clinical = in_array($request->user()->role?->name, self::CLINICAL_ROLES, true);

        return [
            'id' => $stay->id,
            'status' => $stay->status,
            'patient' => $stay->relationLoaded('patient') && $stay->patient ? [
                'id' => $stay->patient->id,
                'name' => trim($stay->patient->first_name.' '.$stay->patient->last_name),
                'patient_unique_id' => $stay->patient->patient_unique_id,
            ] : null,
            'service' => $stay->room?->service,
            'room' => $stay->room?->name,
            'bed' => $stay->bed?->code,
            'doctor' => $stay->doctor?->name,
            'admitted_at' => $stay->admitted_at?->toIso8601String(),
            'since_days' => $stay->admitted_at ? max(0, (int) $stay->admitted_at->diffInDays(Carbon::now())) : null,
            'reason' => $clinical ? $stay->reason : null,
        ];
    }
}
