<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\ImagingExam;
use App\Models\ImagingFile;
use App\Models\ImagingRequest;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ImagingRequestController extends Controller
{
    private const RELATIONS = [
        'patient:id,first_name,last_name,patient_unique_id,date_of_birth,gender',
        'doctor:id,name',
        'consultation:id,consultation_datetime',
        'exam:id,exam_type,name,price',
        'reports:id,request_id,radiologist_id,report_text,signed_at,updated_at',
        'reports.radiologist:id,name',
        'files:id,request_id,file_type,file_name,file_size,uploaded_at,uploaded_by',
    ];

    public function exams(Request $request)
    {
        $clinicId = $this->clinicId($request);

        return response()->json([
            'data' => $clinicId
                ? ImagingExam::query()
                    ->where('clinic_id', $clinicId)
                    ->when(!$request->boolean('all'), fn ($query) => $query->where('is_active', true))
                    ->orderBy('exam_type')
                    ->orderBy('name')
                    ->get(['id', 'exam_type', 'name', 'price', 'is_active'])
                : [],
        ]);
    }

    public function addExam(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Select a clinic before managing its imaging catalogue.'], 422);
        }
        $validated = $request->validate($this->examRules($clinicId));

        return response()->json(['data' => ImagingExam::create([...$validated, 'clinic_id' => $clinicId])], 201);
    }

    public function updateExam(Request $request, ImagingExam $imagingExam)
    {
        if ((int) $imagingExam->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Examen introuvable.'], 404);
        }
        $rules = collect($this->examRules((int) $imagingExam->clinic_id, $imagingExam->id))
            ->map(fn (array $rule) => ['sometimes', ...$rule])
            ->all();
        $imagingExam->update($request->validate([...$rules, 'is_active' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $imagingExam->fresh()]);
    }

    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['OPEN', ...array_keys(ImagingRequest::STATUS_LABELS)])],
            'patient_id' => ['sometimes', 'nullable', 'integer'],
            'consultation_id' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $scope = fn ($query) => $query
            ->when($clinicId !== null, fn ($inner) => $inner->where('clinic_id', $clinicId))
            ->when($request->user()->role?->name === 'DOCTOR', fn ($inner) => $inner->where('doctor_id', $request->user()->id));

        $requests = ImagingRequest::query()
            ->with(self::RELATIONS)
            ->tap($scope)
            ->when(($filters['status'] ?? null) === 'OPEN', fn ($query) => $query->where('status', '!=', 'SIGNED'))
            ->when(isset($filters['status']) && $filters['status'] !== 'OPEN', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['patient_id'] ?? null, fn ($query, $patientId) => $query->where('patient_id', $patientId))
            ->when($filters['consultation_id'] ?? null, fn ($query, $consultationId) => $query->where('consultation_id', $consultationId))
            ->when($search !== '', fn ($query) => $query->whereHas('patient', fn ($patients) => $patients
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('patient_unique_id', 'like', "%{$search}%")))
            ->orderByRaw("CASE WHEN status = 'SIGNED' THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN priority = 'URGENT' THEN 0 ELSE 1 END")
            ->orderBy('requested_at', ($filters['status'] ?? null) === 'SIGNED' ? 'desc' : 'asc')
            ->paginate(20);
        $counts = ImagingRequest::query()->tap($scope)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([...$requests->toArray(), 'counts' => $counts]);
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Select a clinic before requesting imaging.'], 422);
        }
        $validated = $request->validate([
            'consultation_id' => ['required', 'integer', Rule::exists('consultations', 'id')->where('clinic_id', $clinicId)],
            'exam_id' => ['required', 'integer', Rule::exists('imaging_exams', 'id')->where('clinic_id', $clinicId)->where('is_active', true)],
            'priority' => ['sometimes', Rule::in(['ROUTINE', 'URGENT'])],
            'clinical_info' => ['nullable', 'string', 'max:2000'],
        ]);

        $consultation = Consultation::query()->whereKey($validated['consultation_id'])->firstOrFail();
        if ($request->user()->role?->name === 'DOCTOR' && (int) $consultation->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'You can only request imaging for your own consultations.'], 403);
        }

        $imagingRequest = ImagingRequest::create([
            'clinic_id' => $clinicId,
            'patient_id' => $consultation->patient_id,
            'doctor_id' => $consultation->doctor_id,
            'exam_id' => $validated['exam_id'],
            'consultation_id' => $consultation->id,
            'requested_at' => now(),
            'status' => 'PENDING',
            'priority' => $validated['priority'] ?? 'ROUTINE',
            'clinical_info' => $validated['clinical_info'] ?? null,
        ]);

        return response()->json(['data' => $imagingRequest->load(self::RELATIONS)], 201);
    }

    public function show(Request $request, ImagingRequest $imagingRequest)
    {
        if (!$this->canAccess($request, $imagingRequest)) {
            return response()->json(['message' => 'Imaging request not found.'], 404);
        }

        return response()->json(['data' => [
            ...$imagingRequest->load([...self::RELATIONS, 'clinic:id,name,city,country,admin_phone,admin_email'])->toArray(),
            'status_label' => ImagingRequest::STATUS_LABELS[$imagingRequest->status] ?? $imagingRequest->status,
        ]]);
    }

    /** The radiologist marks the exam as performed. */
    public function update(Request $request, ImagingRequest $imagingRequest)
    {
        if (!$this->canAccess($request, $imagingRequest)) {
            return response()->json(['message' => 'Imaging request not found.'], 404);
        }
        if ($request->user()->role?->name !== 'RADIOLOGIST') {
            return response()->json(['message' => 'Only radiology staff can update imaging request status.'], 403);
        }
        $validated = $request->validate(['status' => ['required', Rule::in(['IN_PROGRESS'])]]);
        if ($imagingRequest->status !== 'PENDING') {
            return response()->json(['message' => 'This imaging request status transition is not allowed.'], 422);
        }
        $imagingRequest->update($validated);

        return response()->json(['data' => $imagingRequest->fresh()->load(self::RELATIONS)]);
    }

    public function uploadFile(Request $request, ImagingRequest $imagingRequest)
    {
        if (!$this->canAccess($request, $imagingRequest)) {
            return response()->json(['message' => 'Imaging request not found.'], 404);
        }
        if (!in_array($imagingRequest->status, ['IN_PROGRESS', 'READY'], true)) {
            return response()->json(['message' => $imagingRequest->status === 'PENDING'
                ? 'Marquez d’abord l’examen comme réalisé.'
                : 'Un examen dont le compte rendu est signé ne reçoit plus de fichiers.'], 422);
        }
        // Heavy DICOM series may go up to 200 MB; other images and PDFs stay at 20 MB.
        $request->validate(['file' => ['required', 'file', 'max:204800', 'extensions:jpg,jpeg,png,pdf,dcm']], [
            'file.max' => 'Le fichier ne doit pas dépasser 200 Mo.',
            'file.extensions' => 'Formats acceptés : JPEG, PNG, PDF ou DICOM (.dcm).',
        ]);
        $upload = $request->file('file');
        $isDicom = strtolower($upload->getClientOriginalExtension()) === 'dcm';
        if (!$isDicom && $upload->getSize() > 20 * 1024 * 1024) {
            return response()->json(['message' => 'Un JPEG, PNG ou PDF ne doit pas dépasser 20 Mo. Les images lourdes s’envoient au format DICOM (.dcm).'], 422);
        }
        // A DICOM file carries the "DICM" signature after its 128-byte preamble: a renamed file is refused.
        if ($isDicom && substr((string) file_get_contents($upload->getRealPath(), false, null, 128, 4), 0, 4) !== 'DICM') {
            return response()->json(['message' => 'Ce fichier n’est pas un DICOM valide.'], 422);
        }
        if ($imagingRequest->files()->count() >= 30) {
            return response()->json(['message' => 'Trente fichiers maximum par examen.'], 422);
        }

        $extension = strtolower($upload->getClientOriginalExtension());
        $path = $upload->storeAs("imaging/{$imagingRequest->clinic_id}/{$imagingRequest->id}", Str::uuid().'.'.$extension, 'local');
        $file = $imagingRequest->files()->create([
            'file_type' => match ($extension) { 'jpg', 'jpeg' => 'JPG', 'png' => 'PNG', 'pdf' => 'PDF', default => 'DICOM' },
            'file_path' => $path,
            'file_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'file_size' => $upload->getSize(),
            'uploaded_at' => now(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $file, 'message' => 'Fichier ajouté.'], 201);
    }

    public function downloadFile(Request $request, ImagingRequest $imagingRequest, ImagingFile $file)
    {
        if (!$this->canAccess($request, $imagingRequest) || (int) $file->request_id !== (int) $imagingRequest->id
            || !Storage::disk('local')->exists($file->file_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($file->file_path, $file->file_name);
    }

    public function deleteFile(Request $request, ImagingRequest $imagingRequest, ImagingFile $file)
    {
        if (!$this->canAccess($request, $imagingRequest) || (int) $file->request_id !== (int) $imagingRequest->id) {
            return response()->json(['message' => 'Fichier introuvable.'], 404);
        }
        if ($imagingRequest->status === 'SIGNED') {
            return response()->json(['message' => 'Un examen signé ne peut plus être modifié.'], 422);
        }
        Storage::disk('local')->delete($file->file_path);
        $file->delete();

        return response()->noContent();
    }

    public function saveReport(Request $request, ImagingRequest $imagingRequest)
    {
        if (!$this->canAccess($request, $imagingRequest)) {
            return response()->json(['message' => 'Imaging request not found.'], 404);
        }
        if ($request->user()->role?->name !== 'RADIOLOGIST' || !in_array($imagingRequest->status, ['IN_PROGRESS', 'READY'], true)) {
            return response()->json(['message' => 'Only radiologists can report imaging in progress.'], 403);
        }
        $validated = $request->validate(['report_text' => ['required', 'string', 'min:20', 'max:50000']], [
            'report_text.min' => 'Le compte rendu est trop court.',
        ]);

        $report = DB::table('imaging_reports')->where('request_id', $imagingRequest->id)->first();
        if ($report?->signed_at !== null) {
            return response()->json(['message' => 'A signed imaging report cannot be modified.'], 422);
        }
        DB::transaction(function () use ($imagingRequest, $request, $validated, $report) {
            // Through the model so that report_text is encrypted at rest.
            \App\Models\ImagingReport::query()->updateOrCreate(
                ['request_id' => $imagingRequest->id],
                [
                    'radiologist_id' => $request->user()->id,
                    'report_text' => $validated['report_text'],
                    'signed_at' => null,
                ],
            );
            $imagingRequest->update(['status' => 'READY']);
        });

        return response()->json(['data' => $imagingRequest->fresh()->load(self::RELATIONS), 'message' => 'Compte rendu enregistré.']);
    }

    public function signReport(Request $request, ImagingRequest $imagingRequest)
    {
        if (!$this->canAccess($request, $imagingRequest)) {
            return response()->json(['message' => 'Imaging request not found.'], 404);
        }
        if ($request->user()->role?->name !== 'RADIOLOGIST') {
            return response()->json(['message' => 'Only radiologists can sign imaging reports.'], 403);
        }
        $report = DB::table('imaging_reports')->where('request_id', $imagingRequest->id)->first();
        if (!$report || $report->signed_at !== null || $imagingRequest->status !== 'READY') {
            return response()->json(['message' => 'A report must be saved for a ready imaging request before it can be signed.'], 422);
        }

        DB::transaction(function () use ($imagingRequest, $report, $request): void {
            DB::table('imaging_reports')->where('id', $report->id)->update([
                'signed_at' => now(),
                'radiologist_id' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $imagingRequest->update(['status' => 'SIGNED']);
            $patient = $imagingRequest->patient;
            UserNotification::create([
                'clinic_id' => $imagingRequest->clinic_id,
                'user_id' => $imagingRequest->doctor_id,
                'type' => 'IMAGING_RESULT',
                'title' => 'Compte rendu d’imagerie disponible',
                'message' => "{$patient->first_name} {$patient->last_name} : {$imagingRequest->exam->name}, compte rendu signé.",
            ]);
        });

        return response()->json(['data' => $imagingRequest->fresh()->load(self::RELATIONS)]);
    }

    public function destroy(Request $request, ImagingRequest $imagingRequest)
    {
        if (!$this->canAccess($request, $imagingRequest)) {
            return response()->json(['message' => 'Imaging request not found.'], 404);
        }
        if ($imagingRequest->status !== 'PENDING') {
            return response()->json(['message' => 'Only pending imaging requests can be cancelled.'], 422);
        }
        $billed = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.reference_id', 'imaging:'.$imagingRequest->id)
            ->where('invoices.status', '!=', 'CANCELLED')
            ->whereNull('invoices.deleted_at')
            ->exists();
        if ($billed) {
            return response()->json(['message' => 'Cet examen est déjà facturé : annulez d’abord la facture.'], 422);
        }
        $imagingRequest->delete();

        return response()->noContent();
    }

    /** @return array<string, array<int, mixed>> */
    private function examRules(int $clinicId, ?int $ignoreId = null): array
    {
        return [
            'exam_type' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255', Rule::unique('imaging_exams', 'name')->where('clinic_id', $clinicId)->ignore($ignoreId)],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    private function canAccess(Request $request, ImagingRequest $imagingRequest): bool
    {
        $clinicId = $this->clinicId($request);

        return ($clinicId === null || (int) $imagingRequest->clinic_id === $clinicId)
            && ($request->user()->role?->name !== 'DOCTOR' || (int) $imagingRequest->doctor_id === (int) $request->user()->id);
    }

    private function clinicId(Request $request): ?int
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return $clinicId === null ? null : (int) $clinicId;
    }
}
