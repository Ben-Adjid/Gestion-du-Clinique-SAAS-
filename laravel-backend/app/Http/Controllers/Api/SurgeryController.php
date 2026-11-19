<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Surgery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operating theatre: planning of operations, team, operative report and post-operative follow-up.
 * The report can be written and signed only by the assigned surgeon or anesthetist.
 */
class SurgeryController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,patient_unique_id,date_of_birth', 'surgeon:id,name', 'anesthetist:id,name', 'staff:id,name'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'nullable', Rule::in(Surgery::STATUSES)],
        ]);
        $query = Surgery::query()->with(self::RELATIONS)->orderBy('scheduled_at');
        if (!empty($validated['date'])) {
            $query->whereDate('scheduled_at', $validated['date']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn (Surgery $surgery) => $this->present($surgery, $request))]);
    }

    /** Who can be named in a team: doctors (surgeon, anesthetist) and nurses (operating team). */
    public function teamOptions(Request $request): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;
        $people = User::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->where('status', 'ACTIVE')
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['DOCTOR', 'NURSE']))
            ->with('role:id,name')->orderBy('name')->get(['id', 'name', 'role_id']);

        return response()->json(['data' => [
            'doctors' => $people->filter(fn (User $user) => $user->role?->name === 'DOCTOR')->map->only(['id', 'name'])->values(),
            'nurses' => $people->filter(fn (User $user) => $user->role?->name === 'NURSE')->map->only(['id', 'name'])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)->whereNull('deleted_at')],
            'operation_type' => ['required', 'string', 'max:150'],
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i', 'after:now'],
            'room' => ['nullable', 'string', 'max:60'],
            'surgeon_id' => ['required', 'integer', 'exists:users,id'],
            'anesthetist_id' => ['nullable', 'integer', 'exists:users,id', 'different:surgeon_id'],
            'staff' => ['sometimes', 'array', 'max:20'],
            'staff.*' => ['integer', 'exists:users,id'],
        ]);
        $this->assertDoctor((int) $validated['surgeon_id'], $clinicId, 'Le chirurgien doit être un médecin de la clinique.');
        if (!empty($validated['anesthetist_id'])) {
            $this->assertDoctor((int) $validated['anesthetist_id'], $clinicId, 'L’anesthésiste doit être un médecin de la clinique.');
        }
        $staff = $this->assertNursesOnly($validated['staff'] ?? [], $clinicId);

        $surgery = DB::transaction(function () use ($validated, $clinicId, $request, $staff) {
            $surgery = Surgery::query()->create([
                'clinic_id' => $clinicId,
                'patient_id' => $validated['patient_id'],
                'operation_type' => $validated['operation_type'],
                'scheduled_at' => $validated['scheduled_at'],
                'room' => $validated['room'] ?? null,
                'status' => 'PLANNED',
                'surgeon_id' => $validated['surgeon_id'],
                'anesthetist_id' => $validated['anesthetist_id'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $surgery->staff()->sync(collect($staff)->mapWithKeys(fn ($id) => [$id => ['role' => 'OPERATING_NURSE']]));

            return $surgery;
        });

        return response()->json(['data' => $this->present($surgery->load(self::RELATIONS), $request), 'message' => 'Intervention planifiée.'], 201);
    }

    public function show(Request $request, Surgery $surgery): JsonResponse
    {
        return response()->json(['data' => $this->present($surgery->load(self::RELATIONS), $request)]);
    }

    public function update(Request $request, Surgery $surgery): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;
        $validated = $request->validate([
            'scheduled_at' => ['sometimes', 'date_format:Y-m-d H:i'],
            'room' => ['sometimes', 'nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(Surgery::STATUSES)],
            'anesthetist_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'staff' => ['sometimes', 'array', 'max:20'],
            'staff.*' => ['integer', 'exists:users,id'],
        ]);
        if (array_key_exists('anesthetist_id', $validated) && $validated['anesthetist_id']) {
            $this->assertDoctor((int) $validated['anesthetist_id'], $clinicId, 'L’anesthésiste doit être un médecin de la clinique.');
        }

        DB::transaction(function () use ($surgery, $validated, $clinicId) {
            $surgery->update(collect($validated)->except('staff')->all());
            if (array_key_exists('staff', $validated)) {
                $staff = $this->assertNursesOnly($validated['staff'], $clinicId);
                $surgery->staff()->sync(collect($staff)->mapWithKeys(fn ($id) => [$id => ['role' => 'OPERATING_NURSE']]));
            }
        });

        return response()->json(['data' => $this->present($surgery->fresh()->load(self::RELATIONS), $request), 'message' => 'Intervention mise à jour.']);
    }

    /** Writes or corrects the operative report and the follow-up: assigned surgeon or anesthetist only. */
    public function updateReport(Request $request, Surgery $surgery): JsonResponse
    {
        $this->assertReportAuthor($request, $surgery);
        if ($surgery->report_signed_at !== null) {
            throw ValidationException::withMessages(['report' => 'Le compte rendu est signé : il ne peut plus être modifié.']);
        }
        $validated = $request->validate([
            'report' => ['required', 'string', 'min:20', 'max:20000'],
            'post_op_followup' => ['nullable', 'string', 'max:10000'],
        ], ['report.min' => 'Le compte rendu opératoire est trop court.']);

        $surgery->update([
            'report' => $validated['report'],
            'post_op_followup' => $validated['post_op_followup'] ?? $surgery->post_op_followup,
            'status' => $surgery->status === 'PLANNED' ? 'IN_PROGRESS' : $surgery->status,
        ]);

        return response()->json(['data' => $this->present($surgery->fresh()->load(self::RELATIONS), $request), 'message' => 'Compte rendu enregistré.']);
    }

    /** Signature by the surgeon closes the operation. */
    public function signReport(Request $request, Surgery $surgery): JsonResponse
    {
        $this->assertReportAuthor($request, $surgery);
        if ((int) $surgery->surgeon_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Seul le chirurgien assigné peut signer le compte rendu.'], 403);
        }
        if ($surgery->report_signed_at !== null || blank($surgery->report)) {
            throw ValidationException::withMessages(['report' => 'Un compte rendu rédigé et non signé est requis.']);
        }
        $surgery->update(['report_signed_at' => Carbon::now(), 'status' => 'COMPLETED']);

        return response()->json(['data' => $this->present($surgery->fresh()->load(self::RELATIONS), $request), 'message' => 'Compte rendu signé.']);
    }

    private function assertReportAuthor(Request $request, Surgery $surgery): void
    {
        abort_unless($surgery->isReportAuthor($request->user()), 403, 'Seuls le chirurgien et l’anesthésiste assignés peuvent rédiger ce compte rendu.');
    }

    private function assertDoctor(int $userId, int $clinicId, string $message): void
    {
        $user = User::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->with('role')->find($userId);
        if (!$user || $user->role?->name !== 'DOCTOR' || $user->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['surgeon_id' => $message]);
        }
    }

    /** @return list<int> */
    private function assertNursesOnly(array $userIds, int $clinicId): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if ($ids === []) {
            return [];
        }
        $valid = User::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereIn('id', $ids)->whereHas('role', fn ($query) => $query->where('name', 'NURSE'))
            ->pluck('id')->all();
        if (count($valid) !== count($ids)) {
            throw ValidationException::withMessages(['staff' => 'Le personnel de bloc doit être constitué d’infirmiers de la clinique.']);
        }

        return $valid;
    }

    /** The report is shown to the operating team; the rest of the clinic does not see it. */
    private function present(Surgery $surgery, Request $request): array
    {
        $user = $request->user();
        $inTeam = $surgery->isReportAuthor($user) || $surgery->staff->contains('id', $user->id);

        return [
            ...$surgery->only(['id', 'operation_type', 'scheduled_at', 'room', 'status', 'report_signed_at', 'created_at']),
            'patient' => $surgery->patient,
            'surgeon' => $surgery->surgeon,
            'anesthetist' => $surgery->anesthetist,
            'staff' => $surgery->staff->map->only(['id', 'name']),
            'report' => $inTeam ? $surgery->report : null,
            'post_op_followup' => $inTeam ? $surgery->post_op_followup : null,
            'can_edit_report' => $surgery->isReportAuthor($user) && $surgery->report_signed_at === null,
            'can_sign_report' => (int) $surgery->surgeon_id === (int) $user->id && $surgery->report_signed_at === null,
        ];
    }
}
