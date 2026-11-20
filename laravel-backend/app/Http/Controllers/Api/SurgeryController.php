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
use App\Models\SurgicalConsent;
use Illuminate\Validation\ValidationException;

/**
 * Operating theatre: planning of operations, team, operative report and post-operative follow-up.
 * The report can be written and signed only by the assigned surgeon or anesthetist.
 */
class SurgeryController extends Controller
{
    private const RELATIONS = ['patient:id,first_name,last_name,patient_unique_id,date_of_birth', 'surgeon:id,name', 'anesthetist:id,name', 'staff:id,name'];

    /** Pre-operative checklist: every item must be ticked before the operation starts. */
    public const CHECKLIST = [
        'identity_confirmed' => 'identité du patient vérifiée (bracelet, dossier)',
        'site_marked' => 'site opératoire marqué',
        'allergies_checked' => 'allergies vérifiées',
        'fasting_confirmed' => 'jeûne confirmé',
        'anesthesia_reviewed' => 'consultation d’anesthésie faite',
    ];

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
            'indication_id' => ['required', 'integer', Rule::exists('surgical_indications', 'id')->where('clinic_id', $clinicId)],
            'operation_type' => ['required', 'string', 'max:150'],
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i', 'after:now'],
            'room' => ['nullable', 'string', 'max:60'],
            'estimated_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
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
        $indication = $this->assertSchedulable($validated, $clinicId);
        // The anesthesia who assessed the patient runs the operation unless another is named.
        $validated['anesthetist_id'] ??= $indication->pre_anesthesia_by;

        $surgery = DB::transaction(function () use ($validated, $clinicId, $request, $staff) {
            $surgery = Surgery::query()->create([
                'clinic_id' => $clinicId,
                'patient_id' => $validated['patient_id'],
                'indication_id' => $validated['indication_id'],
                'operation_type' => $validated['operation_type'],
                'scheduled_at' => $validated['scheduled_at'],
                'room' => $validated['room'] ?? null,
                'estimated_minutes' => $validated['estimated_minutes'] ?? null,
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
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:15', 'max:720'],
            'status' => ['sometimes', Rule::in(Surgery::STATUSES)],
            'anesthetist_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'staff' => ['sometimes', 'array', 'max:20'],
            'staff.*' => ['integer', 'exists:users,id'],
        ]);
        if (array_key_exists('anesthetist_id', $validated) && $validated['anesthetist_id']) {
            $this->assertDoctor((int) $validated['anesthetist_id'], $clinicId, 'L’anesthésiste doit être un médecin de la clinique.');
        }
        // An operation starts only with the informed consent and a complete pre-operative checklist.
        if (($validated['status'] ?? null) === 'IN_PROGRESS' && $surgery->status !== 'IN_PROGRESS') {
            $missing = $this->missingBeforeStart($surgery);
            if ($missing !== []) {
                throw ValidationException::withMessages(['status' => 'Avant de démarrer l’intervention : '.implode(', ', $missing).'.']);
            }
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
        // The signed operative report carries the surgeon's registration number, as a prescription does.
        if (blank($request->user()->doctorProfile?->license_number)) {
            return response()->json([
                'message' => 'Renseignez votre numéro d’ordre dans vos paramètres avant de signer le compte rendu opératoire.',
                'code' => 'LICENCE_REQUIRED',
            ], 422);
        }
        $surgery->update(['report_signed_at' => Carbon::now(), 'status' => 'COMPLETED']);

        return response()->json(['data' => $this->present($surgery->fresh()->load(self::RELATIONS), $request), 'message' => 'Compte rendu signé.']);
    }

    /** Scheduling needs an approved indication, the head of the specialty, and the anesthesia assessment. */
    private function assertSchedulable(array $validated, int $clinicId): \App\Models\SurgicalIndication
    {
        $indication = \App\Models\SurgicalIndication::query()->findOrFail($validated['indication_id']);
        if ((int) $indication->patient_id !== (int) $validated['patient_id']) {
            throw ValidationException::withMessages(['indication_id' => 'Cette indication ne concerne pas ce patient.']);
        }
        if ($indication->status === 'REFUSED') {
            throw ValidationException::withMessages(['indication_id' => 'L’indication a été refusée : l’intervention ne peut pas être programmée.']);
        }
        if ($indication->status !== 'APPROVED') {
            throw ValidationException::withMessages(['indication_id' => 'La validation du chef de service est obligatoire avant la programmation.']);
        }
        if (!$indication->readyToSchedule()) {
            throw ValidationException::withMessages(['indication_id' => 'L’évaluation pré-anesthésique est obligatoire avant la programmation (score ASA et allergies vérifiées).']);
        }
        $surgeon = User::query()->with('doctorProfile')->find($validated['surgeon_id']);
        if ((int) $surgeon?->doctorProfile?->specialty_id !== (int) $indication->specialty_id) {
            throw ValidationException::withMessages(['surgeon_id' => 'Le chirurgien doit appartenir à la spécialité de l’indication.']);
        }
        if (!empty($validated['anesthetist_id'])) {
            $anesth = User::query()->with('doctorProfile.specialtyRef')->find($validated['anesthetist_id']);
            if ($anesth?->doctorProfile?->specialtyRef?->code !== 'ANESTHESIE_REA') {
                throw ValidationException::withMessages(['anesthetist_id' => 'L’anesthésiste doit être un médecin anesthésiste-réanimateur.']);
            }
        }

        return $indication;
    }

    /** Informed consent, recorded before the operation by the surgeon or the anesthetist. One per operation. */
    public function consent(Request $request, Surgery $surgery): JsonResponse
    {
        $this->assertReportAuthor($request, $surgery);
        if ($surgery->status === 'CANCELLED' || $surgery->status === 'COMPLETED') {
            throw ValidationException::withMessages(['consent' => 'Le consentement se recueille avant l’intervention.']);
        }
        if ($surgery->consent()->exists()) {
            throw ValidationException::withMessages(['consent' => 'Le consentement est déjà recueilli pour cette intervention.']);
        }
        $validated = $request->validate([
            'signer_name' => ['required', 'string', 'min:3', 'max:120'],
            'signer_relation' => ['required', 'string', Rule::in(SurgicalConsent::RELATIONS)],
            'information_text' => ['required', 'string', 'min:30', 'max:4000'],
        ], [
            'information_text.min' => 'Décrivez au patient l’intervention, ses risques et ses alternatives (30 caractères minimum).',
        ]);

        $consent = SurgicalConsent::query()->create([
            'clinic_id' => $surgery->clinic_id,
            'surgery_id' => $surgery->id,
            'patient_id' => $surgery->patient_id,
            ...$validated,
            'recorded_by' => $request->user()->id,
            'signed_at' => Carbon::now(),
        ]);

        return response()->json(['data' => ['id' => $consent->id, 'signer_name' => $consent->signer_name, 'signed_at' => $consent->signed_at->toIso8601String()], 'message' => 'Consentement éclairé recueilli.'], 201);
    }

    /** Pre-operative checklist, updated by the team (surgeon, anesthetist or an operating nurse). */
    public function checklist(Request $request, Surgery $surgery): JsonResponse
    {
        if (!$surgery->isReportAuthor($request->user()) && !$surgery->staff->contains('id', $request->user()->id)) {
            return response()->json(['message' => 'Seule l’équipe opératoire peut remplir la check-list.'], 403);
        }
        if (in_array($surgery->status, ['COMPLETED', 'CANCELLED'], true)) {
            throw ValidationException::withMessages(['checklist' => 'Cette intervention est close : la check-list n’est plus modifiable.']);
        }
        $items = array_keys(self::CHECKLIST);
        $validated = $request->validate(collect($items)->mapWithKeys(fn ($item) => [$item => ['required', 'boolean']])->all());
        $surgery->update(['preop_checklist' => $validated]);

        return response()->json(['data' => $this->present($surgery->fresh()->load(self::RELATIONS), $request), 'message' => 'Check-list enregistrée.']);
    }

    /** Invoice of the operation, through the normal invoice flow (same numbering, same rules). */
    public function invoice(Request $request, Surgery $surgery): JsonResponse
    {
        // Billed by the surgeon or anesthetist who knows the act, or by a user allowed to issue invoices.
        $canInvoice = $surgery->isReportAuthor($request->user())
            || $request->user()->role?->permissions()->where('permissions.name', 'create:invoice')->exists();
        if (!$canInvoice) {
            return response()->json(['message' => 'Seuls le chirurgien ou l’anesthésiste assigné, ou la facturation, peuvent facturer cette intervention.'], 403);
        }
        if ($surgery->invoice_id) {
            throw ValidationException::withMessages(['invoice' => 'Cette intervention est déjà facturée.']);
        }
        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0', 'max:99999999.99']]);

        $request->merge([
            'patient_id' => $surgery->patient_id,
            'items' => [['description' => 'Intervention : '.$surgery->operation_type, 'amount' => $validated['amount']]],
            'status' => 'ISSUED',
        ]);
        $response = app(InvoiceController::class)->store($request);
        if ($response->getStatusCode() !== 201) {
            return $response;
        }
        $payload = json_decode($response->getContent(), true);
        $surgery->update(['invoice_id' => $payload['data']['id']]);

        return response()->json(['data' => ['invoice_id' => $payload['data']['id'], 'invoice_number' => $payload['data']['invoice_number'] ?? null], 'message' => 'Facture de l’intervention émise.'], 201);
    }

    /** Pre-operative items still missing before the operation can start (empty list = ready). */
    private function missingBeforeStart(Surgery $surgery): array
    {
        $missing = [];
        if (!$surgery->consent()->exists()) {
            $missing[] = 'consentement éclairé du patient';
        }
        $checklist = (array) $surgery->preop_checklist;
        foreach (self::CHECKLIST as $key => $label) {
            if (($checklist[$key] ?? false) !== true) {
                $missing[] = 'check-list : '.$label;
            }
        }

        return $missing;
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
            'estimated_minutes' => $surgery->estimated_minutes,
            'preop_checklist' => $inTeam ? (object) ((array) $surgery->preop_checklist) : null,
            'checklist_items' => self::CHECKLIST,
            'consent' => $inTeam && $surgery->consent ? [
                'signer_name' => $surgery->consent->signer_name,
                'signer_relation' => $surgery->consent->signer_relation,
                'signed_at' => $surgery->consent->signed_at?->toIso8601String(),
            ] : null,
            'ready_to_start' => $this->missingBeforeStart($surgery) === [],
            'missing_before_start' => $inTeam ? $this->missingBeforeStart($surgery) : [],
            'invoice_id' => $surgery->invoice_id,
            'can_invoice' => $surgery->isReportAuthor($user) || $user->role?->permissions()->where('permissions.name', 'create:invoice')->exists(),
        ];
    }
}
