<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bed;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Hospital beds: occupancy by ward, admission and discharge of a patient. */
class BedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $beds = Bed::query()->with('patient:id,first_name,last_name,patient_unique_id')->orderBy('ward')->orderBy('code')->get();
        $total = $beds->count();
        $occupied = $beds->where('status', 'OCCUPIED')->count();

        return response()->json(['data' => [
            'occupancy' => [
                'total' => $total,
                'occupied' => $occupied,
                'available' => $beds->where('status', 'AVAILABLE')->count(),
                'maintenance' => $beds->where('status', 'MAINTENANCE')->count(),
                'rate' => $total > 0 ? round($occupied / $total * 100, 1) : 0.0,
            ],
            'wards' => $beds->groupBy('ward')->map(fn ($wardBeds, $ward) => [
                'ward' => $ward,
                'beds' => $wardBeds->map(fn (Bed $bed) => [
                    'id' => $bed->id,
                    'code' => $bed->code,
                    'status' => $bed->status,
                    'note' => $bed->note,
                    'admitted_at' => $bed->admitted_at?->toIso8601String(),
                    'patient' => $bed->patient ? [
                        'id' => $bed->patient->id,
                        'name' => trim($bed->patient->first_name.' '.$bed->patient->last_name),
                        'patient_unique_id' => $bed->patient->patient_unique_id,
                    ] : null,
                ])->values(),
            ])->values(),
        ]]);
    }

    /** Creates a bed in a ward (the code is unique in the clinic). */
    public function store(Request $request): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;
        $validated = $request->validate([
            'ward' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:20', Rule::unique('beds', 'code')->where('clinic_id', $clinicId)],
        ], ['code.unique' => 'Ce numéro de lit existe déjà dans la clinique.']);

        $bed = Bed::query()->create([...$validated, 'clinic_id' => $clinicId, 'status' => 'AVAILABLE', 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $bed, 'message' => 'Lit créé.'], 201);
    }

    /** Admits a patient into a free bed. */
    public function assign(Request $request, Bed $bed): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $bed->clinic_id)->whereNull('deleted_at')],
        ]);
        if ($bed->status !== 'AVAILABLE') {
            throw ValidationException::withMessages(['bed' => 'Ce lit n’est pas libre.']);
        }
        if (Bed::query()->where('patient_id', $validated['patient_id'])->where('status', 'OCCUPIED')->exists()) {
            throw ValidationException::withMessages(['patient_id' => 'Ce patient occupe déjà un lit.']);
        }

        DB::transaction(fn () => $bed->update([
            'status' => 'OCCUPIED',
            'patient_id' => $validated['patient_id'],
            'admitted_at' => Carbon::now(),
            'updated_by' => $request->user()->id,
        ]));

        return response()->json(['data' => $bed->fresh()->load('patient:id,first_name,last_name,patient_unique_id'), 'message' => 'Patient installé.']);
    }

    /** Discharges the patient and frees the bed. */
    public function release(Request $request, Bed $bed): JsonResponse
    {
        if ($bed->status !== 'OCCUPIED') {
            throw ValidationException::withMessages(['bed' => 'Ce lit n’est pas occupé.']);
        }
        // The bed is disinfected before any new admission: it goes to CLEANING, not straight to AVAILABLE.
        $bed->update(['status' => 'CLEANING', 'patient_id' => null, 'admitted_at' => null, 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $bed->fresh(), 'message' => 'Lit libéré : en désinfection.']);
    }

    /** Puts a disinfected bed back at the disposal of the ward. */
    public function ready(Request $request, Bed $bed): JsonResponse
    {
        if ($bed->status !== 'CLEANING') {
            throw ValidationException::withMessages(['bed' => 'Ce lit n’est pas en désinfection.']);
        }
        $bed->update(['status' => 'AVAILABLE', 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $bed->fresh(), 'message' => 'Lit prêt à l’admission.']);
    }

    /** Puts a free bed out of service, or back into service. */
    public function maintenance(Request $request, Bed $bed): JsonResponse
    {
        $validated = $request->validate(['out_of_service' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        if ($validated['out_of_service'] && $bed->status === 'OCCUPIED') {
            throw ValidationException::withMessages(['bed' => 'Libérez d’abord le lit avant de le mettre hors service.']);
        }
        $bed->update([
            'status' => $validated['out_of_service'] ? 'MAINTENANCE' : 'AVAILABLE',
            'note' => $validated['out_of_service'] ? ($validated['note'] ?? null) : null,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $bed->fresh()]);
    }
}
