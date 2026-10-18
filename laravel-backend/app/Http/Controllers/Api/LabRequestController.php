<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\LabExam;
use App\Models\LabRequest;
use App\Models\LabResult;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LabRequestController extends Controller
{
    private const RELATIONS = [
        'patient:id,first_name,last_name,patient_unique_id,date_of_birth,gender',
        'doctor:id,name',
        'consultation:id,consultation_datetime',
        'exams:id,exam_type,name,unit,reference_min,reference_max,sample_type,price',
        'results.exam:id,name,exam_type',
        'samples.collector:id,name',
    ];

    public function exams(Request $request)
    {
        $clinicId = $this->clinicId($request);

        return response()->json([
            'data' => $clinicId
                ? LabExam::query()
                    ->where('clinic_id', $clinicId)
                    ->when(!$request->boolean('all'), fn ($query) => $query->where('is_active', true))
                    ->orderBy('exam_type')
                    ->orderBy('name')
                    ->get(['id', 'exam_type', 'name', 'price', 'unit', 'reference_min', 'reference_max', 'sample_type', 'is_active'])
                : [],
        ]);
    }

    public function addExam(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Select a clinic before managing its exam catalogue.'], 422);
        }
        $validated = $request->validate($this->examRules($clinicId));

        return response()->json(['data' => LabExam::create([...$validated, 'clinic_id' => $clinicId])], 201);
    }

    public function updateExam(Request $request, LabExam $labExam)
    {
        if ((int) $labExam->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Examen introuvable.'], 404);
        }
        $rules = collect($this->examRules((int) $labExam->clinic_id, $labExam->id))
            ->map(fn (array $rule) => ['sometimes', ...$rule])
            ->all();
        $labExam->update($request->validate([...$rules, 'is_active' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $labExam->fresh()]);
    }

    /** Laboratory worklist: open requests first, urgent ones on top. */
    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['OPEN', ...array_keys(LabRequest::STATUS_LABELS)])],
            'priority' => ['sometimes', 'nullable', Rule::in(['ROUTINE', 'URGENT'])],
            'patient_id' => ['sometimes', 'nullable', 'integer'],
            'consultation_id' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $requests = LabRequest::query()
            ->with(self::RELATIONS)
            ->when($clinicId !== null, fn ($query) => $query->where('clinic_id', $clinicId))
            ->when($request->user()->role?->name === 'DOCTOR', fn ($query) => $query->where('doctor_id', $request->user()->id))
            ->when(($filters['status'] ?? null) === 'OPEN', fn ($query) => $query->where('status', '!=', 'SIGNED'))
            ->when(isset($filters['status']) && $filters['status'] !== 'OPEN', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['patient_id'] ?? null, fn ($query, $patientId) => $query->where('patient_id', $patientId))
            ->when($filters['consultation_id'] ?? null, fn ($query, $consultationId) => $query->where('consultation_id', $consultationId))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('sample_number', 'like', "%{$search}%")
                ->orWhereHas('patient', fn ($patients) => $patients
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('patient_unique_id', 'like', "%{$search}%"))))
            ->orderByRaw("CASE WHEN status = 'SIGNED' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN priority = 'URGENT' THEN 0 ELSE 1 END")
            ->orderBy(($filters['status'] ?? null) === 'SIGNED' ? 'updated_at' : 'requested_at', ($filters['status'] ?? null) === 'SIGNED' ? 'desc' : 'asc')
            ->paginate(20);

        $counts = LabRequest::query()
            ->when($clinicId !== null, fn ($query) => $query->where('clinic_id', $clinicId))
            ->when($request->user()->role?->name === 'DOCTOR', fn ($query) => $query->where('doctor_id', $request->user()->id))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([...$requests->toArray(), 'counts' => $counts]);
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Select a clinic before requesting laboratory tests.'], 422);
        }

        $validated = $request->validate([
            'consultation_id' => ['required', 'integer', Rule::exists('consultations', 'id')->where('clinic_id', $clinicId)],
            'exam_ids' => ['required', 'array', 'min:1', 'max:30'],
            'exam_ids.*' => ['required', 'integer', 'distinct', Rule::exists('lab_exams', 'id')->where('clinic_id', $clinicId)->where('is_active', true)],
            'priority' => ['sometimes', Rule::in(['ROUTINE', 'URGENT'])],
            'clinical_info' => ['nullable', 'string', 'max:2000'],
        ], ['exam_ids.required' => 'Choisissez au moins un examen.']);
        $consultation = Consultation::query()->whereKey($validated['consultation_id'])->firstOrFail();
        if ($request->user()->role?->name === 'DOCTOR' && (int) $consultation->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'You can only request tests for your own consultations.'], 403);
        }

        $labRequest = DB::transaction(function () use ($clinicId, $consultation, $validated): LabRequest {
            $labRequest = LabRequest::create([
                'clinic_id' => $clinicId,
                'patient_id' => $consultation->patient_id,
                'doctor_id' => $consultation->doctor_id,
                'consultation_id' => $consultation->id,
                'requested_at' => now(),
                'status' => 'PENDING',
                'priority' => $validated['priority'] ?? 'ROUTINE',
                'clinical_info' => $validated['clinical_info'] ?? null,
            ]);
            $labRequest->exams()->sync($validated['exam_ids']);

            return $labRequest;
        });
        if ($labRequest->priority === 'URGENT') {
            $this->notifyRole($labRequest->clinic_id, 'LABORATORY_TECHNICIAN', 'Analyse urgente', 'Une demande d’analyse urgente vient d’être prescrite.');
        }

        return response()->json(['data' => $labRequest->load(self::RELATIONS)], 201);
    }

    public function show(Request $request, LabRequest $labRequest)
    {
        if (!$this->canAccess($request, $labRequest)) {
            return response()->json(['message' => 'Laboratory request not found.'], 404);
        }

        return response()->json(['data' => [
            ...$labRequest->load([...self::RELATIONS, 'results.validator:id,name', 'clinic:id,name,city,country,admin_phone,admin_email'])->toArray(),
            'status_label' => LabRequest::STATUS_LABELS[$labRequest->status] ?? $labRequest->status,
        ]]);
    }

    /** Sample collection: gives the tube its sample number. */
    public function collect(Request $request, LabRequest $labRequest)
    {
        if (!$this->canAccess($request, $labRequest)) {
            return response()->json(['message' => 'Laboratory request not found.'], 404);
        }
        $validated = $request->validate([
            'sample_type' => ['required', 'string', 'max:100'],
            'collected_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        if ($labRequest->status !== 'PENDING') {
            return response()->json(['message' => 'Le prélèvement de cette demande est déjà enregistré.'], 422);
        }

        DB::transaction(function () use ($labRequest, $validated, $request) {
            // Unique across clinics: L{clinic}-{yymmdd}-{sequence of the day}.
            $prefix = 'L'.$labRequest->clinic_id.'-'.now()->format('ymd').'-';
            $last = LabRequest::query()
                ->where('clinic_id', $labRequest->clinic_id)
                ->where('sample_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('sample_number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
                ->max() ?? 0;
            $labRequest->samples()->create([
                'sample_type' => $validated['sample_type'],
                'collected_at' => $validated['collected_at'] ?? now(),
                'collected_by' => $request->user()->id,
            ]);
            $labRequest->update([
                'status' => 'RECEIVED',
                'sample_number' => $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT),
            ]);
        });

        return response()->json(['data' => $labRequest->fresh()->load(self::RELATIONS), 'message' => 'Prélèvement enregistré.']);
    }

    public function update(Request $request, LabRequest $labRequest)
    {
        if (!$this->canAccess($request, $labRequest)) {
            return response()->json(['message' => 'Laboratory request not found.'], 404);
        }
        if ($request->user()->role?->name !== 'LABORATORY_TECHNICIAN') {
            return response()->json(['message' => 'Only laboratory staff can update request status.'], 403);
        }
        $validated = $request->validate([
            'status' => ['required', Rule::in(['IN_PROGRESS'])],
        ]);
        if ($labRequest->status !== 'RECEIVED') {
            return response()->json(['message' => 'This laboratory request status transition is not allowed.'], 422);
        }
        $labRequest->update($validated);

        return response()->json(['data' => $labRequest->fresh()->load(self::RELATIONS)]);
    }

    /** Enters (or corrects) results; the flag is computed from the exam's reference range. */
    public function recordResult(Request $request, LabRequest $labRequest)
    {
        if (!$this->canAccess($request, $labRequest)) {
            return response()->json(['message' => 'Laboratory request not found.'], 404);
        }
        if ($request->user()->role?->name !== 'LABORATORY_TECHNICIAN') {
            return response()->json(['message' => 'Seul le personnel du laboratoire saisit les résultats.'], 403);
        }
        if (!in_array($labRequest->status, ['RECEIVED', 'IN_PROGRESS', 'READY'], true)) {
            return response()->json(['message' => $labRequest->status === 'PENDING'
                ? 'Enregistrez d’abord le prélèvement.'
                : 'Les résultats validés ne peuvent plus être modifiés.'], 422);
        }

        // Accepts a single result (exam_id + result_value) or a batch (results[]).
        $payload = $request->has('results') ? $request->all() : ['results' => [$request->all()]];
        $validated = validator($payload, [
            'results' => ['required', 'array', 'min:1', 'max:30'],
            'results.*.exam_id' => ['required', 'integer', 'distinct', Rule::exists('lab_request_exams', 'lab_exam_id')->where('lab_request_id', $labRequest->id)],
            'results.*.result_value' => ['required', 'string', 'max:255'],
            'results.*.comment' => ['nullable', 'string', 'max:2000'],
            'results.*.abnormal' => ['sometimes', 'boolean'],
        ], [
            'results.*.exam_id.exists' => 'Cet examen ne fait pas partie de la demande.',
            'results.*.result_value.required' => 'Saisissez une valeur.',
        ])->validate();

        DB::transaction(function () use ($labRequest, $validated, $request) {
            $exams = LabExam::query()->whereIn('id', collect($validated['results'])->pluck('exam_id'))->get()->keyBy('id');
            foreach ($validated['results'] as $entry) {
                $exam = $exams[$entry['exam_id']];
                $flag = LabExam::flagFor($entry['result_value'], $exam->reference_min, $exam->reference_max)
                    ?? (($entry['abnormal'] ?? false) ? 'ABNORMAL' : null);
                LabResult::updateOrCreate(
                    ['request_id' => $labRequest->id, 'exam_id' => $exam->id],
                    [
                        'result_value' => trim($entry['result_value']),
                        'unit' => $exam->unit,
                        'reference_min' => $exam->reference_min,
                        'reference_max' => $exam->reference_max,
                        'flag' => $flag,
                        'comment' => $entry['comment'] ?? null,
                        'entered_by' => $request->user()->id,
                        'status' => 'DRAFT',
                        'validator_id' => null,
                        'validated_at' => null,
                    ],
                );
            }
            $complete = $labRequest->results()->count() >= $labRequest->exams()->count();
            $labRequest->update(['status' => $complete ? 'READY' : 'IN_PROGRESS']);
        });

        return response()->json(['data' => $labRequest->fresh()->load(self::RELATIONS), 'message' => 'Résultats enregistrés.']);
    }

    public function signResults(Request $request, LabRequest $labRequest)
    {
        if (!$this->canAccess($request, $labRequest)) {
            return response()->json(['message' => 'Laboratory request not found.'], 404);
        }
        if ($request->user()->role?->name !== 'LABORATORY_TECHNICIAN' || $labRequest->status !== 'READY') {
            return response()->json(['message' => 'Only laboratory staff can sign ready results.'], 403);
        }
        $expectedCount = $labRequest->exams()->count();
        $resultCount = $labRequest->results()->count();
        if ($expectedCount === 0 || $expectedCount !== $resultCount) {
            return response()->json(['message' => 'Record a result for every requested exam before signing.'], 422);
        }

        $abnormal = DB::transaction(function () use ($labRequest, $request): int {
            $labRequest->results()->update(['status' => 'SIGNED', 'validator_id' => $request->user()->id, 'validated_at' => now(), 'updated_at' => now()]);
            $labRequest->update(['status' => 'SIGNED']);
            $abnormal = $labRequest->results()->whereIn('flag', ['LOW', 'HIGH', 'ABNORMAL'])->count();
            $patient = $labRequest->patient;
            UserNotification::create([
                'clinic_id' => $labRequest->clinic_id,
                'user_id' => $labRequest->doctor_id,
                'type' => 'LAB_RESULT',
                'title' => $abnormal > 0 ? 'Résultats de laboratoire anormaux' : 'Résultats de laboratoire disponibles',
                'message' => "{$patient->first_name} {$patient->last_name} : résultats validés".($abnormal > 0 ? " ({$abnormal} valeur(s) hors normes)." : '.'),
            ]);

            return $abnormal;
        });

        return response()->json(['data' => $labRequest->fresh()->load(self::RELATIONS), 'abnormal_count' => $abnormal]);
    }

    public function destroy(Request $request, LabRequest $labRequest)
    {
        if (!$this->canAccess($request, $labRequest)) {
            return response()->json(['message' => 'Laboratory request not found.'], 404);
        }
        if ($labRequest->status !== 'PENDING') {
            return response()->json(['message' => 'Only pending laboratory requests can be cancelled.'], 422);
        }
        if ($this->isBilled('lab:'.$labRequest->id)) {
            return response()->json(['message' => 'Cette demande est déjà facturée : annulez d’abord la facture.'], 422);
        }
        $labRequest->delete();

        return response()->noContent();
    }

    /** @return array<string, array<int, mixed>> */
    private function examRules(int $clinicId, ?int $ignoreId = null): array
    {
        return [
            'exam_type' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255', Rule::unique('lab_exams', 'name')->where('clinic_id', $clinicId)->ignore($ignoreId)],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'reference_min' => ['nullable', 'numeric'],
            'reference_max' => ['nullable', 'numeric', Rule::when(request()->filled('reference_min'), ['gte:reference_min'])],
            'sample_type' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function isBilled(string $reference): bool
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_id', $reference)
            ->where('invoices.status', '!=', 'CANCELLED')
            ->whereNull('invoices.deleted_at')
            ->exists();
    }

    private function notifyRole(int $clinicId, string $role, string $title, string $message): void
    {
        User::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'ACTIVE')
            ->whereHas('role', fn ($query) => $query->where('name', $role))
            ->pluck('id')
            ->each(fn (int $userId) => UserNotification::create([
                'clinic_id' => $clinicId,
                'user_id' => $userId,
                'type' => 'LAB_REQUEST',
                'title' => $title,
                'message' => $message,
            ]));
    }

    private function canAccess(Request $request, LabRequest $labRequest): bool
    {
        $clinicId = $this->clinicId($request);

        return ($clinicId === null || (int) $labRequest->clinic_id === $clinicId)
            && ($request->user()->role?->name !== 'DOCTOR' || (int) $labRequest->doctor_id === (int) $request->user()->id);
    }

    private function clinicId(Request $request): ?int
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return $clinicId === null ? null : (int) $clinicId;
    }
}
