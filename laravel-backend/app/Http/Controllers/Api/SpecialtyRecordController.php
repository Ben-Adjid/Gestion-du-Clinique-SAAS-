<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientSpecialtyRecord;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Specialty-specific record of a patient: a dental chart, eye and ENT measures, pregnancy term,
 * or a generic note. The fields depend on the record kind of the specialty.
 */
class SpecialtyRecordController extends Controller
{
    /** FDI tooth numbers: adult permanent teeth. */
    public const TEETH = ['11', '12', '13', '14', '15', '16', '17', '18', '21', '22', '23', '24', '25', '26', '27', '28', '31', '32', '33', '34', '35', '36', '37', '38', '41', '42', '43', '44', '45', '46', '47', '48'];
    public const TOOTH_STATES = ['SAIN', 'CARIE', 'SOIN', 'PROTHESE', 'ABSENT'];

    public function index(Request $request, Patient $patient): JsonResponse
    {
        $records = PatientSpecialtyRecord::query()->where('patient_id', $patient->id)->with(['specialty:id,label,record_kind', 'recorder:id,name'])
            ->latest('recorded_at')->limit(50)->get();

        return response()->json(['data' => $records->map(fn (PatientSpecialtyRecord $record) => [
            'id' => $record->id,
            'specialty' => $record->specialty?->label,
            'kind' => $record->specialty?->record_kind,
            'data' => $record->data,
            'recorded_by' => $record->recorder?->name,
            'recorded_at' => $record->recorded_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->role?->name, ['DOCTOR', 'NURSE'], true)) {
            return response()->json(['message' => 'Les fiches de spécialité sont tenues par le personnel soignant.'], 403);
        }
        $validated = $request->validate(['specialty_code' => ['required', 'string', 'exists:specialties,code']]);
        $specialty = Specialty::query()->where('code', $validated['specialty_code'])->firstOrFail();
        $data = $this->validateData($request, $specialty->record_kind);

        $record = PatientSpecialtyRecord::query()->create([
            'clinic_id' => $patient->clinic_id,
            'patient_id' => $patient->id,
            'specialty_id' => $specialty->id,
            'data' => $data,
            'recorded_by' => $user->id,
            'recorded_at' => Carbon::now(),
        ]);

        return response()->json(['data' => ['id' => $record->id, 'specialty' => $specialty->label, 'data' => $data], 'message' => 'Fiche de spécialité enregistrée.'], 201);
    }

    /** Rules by record kind: a generic note when the specialty has no dedicated template. */
    private function validateData(Request $request, string $kind): array
    {
        return match ($kind) {
            'DENTAL' => $this->dental($request),
            'OPHTALMO' => $request->validate([
                'acuite_od' => ['required', 'string', 'max:10'],
                'acuite_og' => ['required', 'string', 'max:10'],
                'tension_od' => ['nullable', 'integer', 'between:5,60'],
                'tension_og' => ['nullable', 'integer', 'between:5,60'],
                'note' => ['nullable', 'string', 'max:2000'],
            ], ['acuite_od.required' => 'Saisissez l’acuité visuelle de l’œil droit (ex. 10/10).', 'acuite_og.required' => 'Saisissez l’acuité visuelle de l’œil gauche.']),
            'ORL' => $request->validate([
                'audition_od_db' => ['nullable', 'integer', 'between:0,120'],
                'audition_og_db' => ['nullable', 'integer', 'between:0,120'],
                'conduit' => ['nullable', 'string', 'max:255'],
                'note' => ['nullable', 'string', 'max:2000'],
            ]),
            'MATERNITE' => $request->validate([
                'terme_sa' => ['required', 'integer', 'between:4,45'],
                'gestite' => ['nullable', 'integer', 'between:1,20'],
                'parite' => ['nullable', 'string', 'max:20'],
                'note' => ['nullable', 'string', 'max:2000'],
            ], ['terme_sa.required' => 'Saisissez le terme en semaines d’aménorrhée (SA).', 'terme_sa.between' => 'Le terme doit être compris entre 4 et 45 SA.']),
            default => $request->validate(['note' => ['required', 'string', 'min:10', 'max:4000']], ['note.required' => 'Rédigez la note (10 caractères minimum).']),
        };
    }

    /** Dental chart: state of each tooth (FDI numbering), only the teeth that were examined. */
    private function dental(Request $request): array
    {
        $validated = $request->validate([
            'teeth' => ['required', 'array', 'min:1'],
            'teeth.*' => ['string', 'in:'.implode(',', self::TOOTH_STATES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['teeth.required' => 'Renseignez l’état des dents examinées.']);

        $unknown = array_diff(array_keys($validated['teeth']), self::TEETH);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['teeth' => 'Numéros de dent inconnus : '.implode(', ', $unknown).' (numérotation FDI).']);
        }

        return ['teeth' => $validated['teeth'], 'note' => $validated['note'] ?? null];
    }
}
