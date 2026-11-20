<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsultationController extends Controller
{
    private const RELATIONS = [
        'patient:id,first_name,last_name,patient_unique_id,date_of_birth,gender,blood_group',
        'doctor:id,name',
        'appointment:id,appointment_datetime,reason,is_walk_in',
        'sections:id,consultation_id,section_number,title,content',
        'diagnostics:id,consultation_id,cim10_code,cim10_description,is_primary,notes',
    ];

    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $filters = $request->validate([
            'patient_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'SIGNED'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $query = Consultation::query()
            ->with(self::RELATIONS)
            ->when($clinicId !== null, fn ($consultations) => $consultations->where('clinic_id', $clinicId))
            ->when($request->user()->role?->name === 'DOCTOR', fn ($consultations) => $consultations->where('doctor_id', $request->user()->id))
            ->when($request->user()->role?->name === 'PATIENT', function ($consultations) use ($request) {
                $consultations->where('patient_id', $request->user()->patient?->id ?? 0)
                    ->where('status', 'SIGNED');
            })
            ->when($filters['patient_id'] ?? null, fn ($consultations, $patientId) => $consultations->where('patient_id', $patientId))
            ->when($filters['status'] ?? null, fn ($consultations, $status) => $consultations->where('status', $status))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($consultations) use ($filters) {
                $search = trim($filters['search']);
                $consultations->whereHas('patient', fn ($patients) => $patients
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('patient_unique_id', 'like', "%{$search}%"));
            })
            ->latest('consultation_datetime');

        return response()->json($query->paginate(30));
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        if (!$clinicId) {
            return response()->json(['message' => 'Choisissez une clinique avant de créer une consultation.'], 422);
        }

        $validated = $request->validate([
            'patient_id' => ['required_without:appointment_id', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)->whereNull('deleted_at')],
            'appointment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('appointments', 'id')->where('clinic_id', $clinicId)],
            'doctor_id' => ['sometimes', 'integer', Rule::exists('users', 'id')->where('clinic_id', $clinicId)],
            'consultation_datetime' => ['required_without:appointment_id', 'date', 'before_or_equal:+1 day'],
            ...$this->contentRules(),
        ]);
        $doctorId = $request->user()->role?->name === 'DOCTOR'
            ? $request->user()->id
            : ($validated['doctor_id'] ?? null);
        if (!$doctorId) {
            return response()->json(['message' => 'Un médecin doit être assigné à cette consultation.'], 422);
        }

        $doctor = User::query()->with('role')->whereKey($doctorId)->firstOrFail();
        if ($doctor->status !== 'ACTIVE' || $doctor->role?->name !== 'DOCTOR') {
            return response()->json(['message' => 'Choisissez un médecin actif de cette clinique.'], 422);
        }

        $appointment = null;
        if (!empty($validated['appointment_id'])) {
            $appointment = Appointment::findOrFail($validated['appointment_id']);
            if ((int) $appointment->doctor_id !== (int) $doctorId) {
                return response()->json(['message' => 'Ce rendez-vous est attribué à un autre médecin.'], 422);
            }
            if (isset($validated['patient_id']) && (int) $validated['patient_id'] !== (int) $appointment->patient_id) {
                return response()->json(['message' => 'Le patient ne correspond pas au rendez-vous.'], 422);
            }
            if (Consultation::where('appointment_id', $appointment->id)->exists()) {
                return response()->json(['message' => 'Une consultation existe déjà pour ce rendez-vous.'], 409);
            }
            if (!in_array($appointment->status, ['SCHEDULED', 'COMPLETED'], true)) {
                return response()->json(['message' => 'Ce rendez-vous est annulé ou le patient était absent.'], 422);
            }
            if (!$appointment->appointment_datetime->isToday() && $appointment->appointment_datetime->isFuture()) {
                return response()->json(['message' => 'La consultation ne peut pas commencer avant le jour du rendez-vous.'], 422);
            }
        }

        $consultation = DB::transaction(function () use ($validated, $clinicId, $doctorId, $appointment): Consultation {
            $consultation = Consultation::create([
                'clinic_id' => $clinicId,
                'patient_id' => $appointment?->patient_id ?? $validated['patient_id'],
                'doctor_id' => $doctorId,
                'appointment_id' => $appointment?->id,
                'consultation_datetime' => $validated['consultation_datetime'] ?? now(),
                'status' => 'DRAFT',
            ]);
            if ($appointment) {
                $appointment->update([
                    'status' => 'COMPLETED',
                    'checked_in_at' => $appointment->checked_in_at ?? now(),
                    'called_at' => $appointment->called_at ?? now(),
                ]);
                if (empty($validated['sections']) && $appointment->reason) {
                    $validated['sections'] = [['section_number' => 1, 'content' => $appointment->reason]];
                }
            }
            $this->saveContent($consultation, $validated);

            return $consultation;
        });

        return response()->json(['data' => $consultation->load(self::RELATIONS)], 201);
    }

    public function show(Request $request, Consultation $consultation)
    {
        if (!$this->canAccess($request, $consultation)) {
            return response()->json(['message' => 'Consultation introuvable.'], 404);
        }

        return response()->json(['data' => $consultation->load([
            ...self::RELATIONS,
            'vitalSigns',
            'digitalSignatures:id,consultation_id,user_id,signed_at,signature_hash',
        ])]);
    }

    public function update(Request $request, Consultation $consultation)
    {
        if (!$this->canAccess($request, $consultation)) {
            return response()->json(['message' => 'Consultation introuvable.'], 404);
        }
        if ($consultation->status === 'SIGNED') {
            return response()->json(['message' => 'Une consultation signée ne peut plus être modifiée.'], 422);
        }

        $validated = $request->validate([
            'consultation_datetime' => ['sometimes', 'required', 'date', 'before_or_equal:+1 day'],
            ...$this->contentRules(),
            'status' => ['sometimes', Rule::in(['DRAFT', 'IN_PROGRESS', 'COMPLETED'])],
        ]);

        DB::transaction(function () use ($consultation, $validated): void {
            $consultation->update(collect($validated)->only(['consultation_datetime'])->all());
            $this->saveContent($consultation, $validated);

            if (isset($validated['status'])) {
                if ($validated['status'] === 'COMPLETED') {
                    $this->assertCompletable($consultation);
                }
                $consultation->update(['status' => $validated['status']]);
            }
        });

        return response()->json(['data' => $consultation->fresh()->load(self::RELATIONS)]);
    }

    public function destroy(Request $request, Consultation $consultation)
    {
        if (!$this->canAccess($request, $consultation)) {
            return response()->json(['message' => 'Consultation introuvable.'], 404);
        }
        if ($consultation->status === 'SIGNED') {
            return response()->json(['message' => 'Une consultation signée ne peut pas être supprimée.'], 422);
        }
        $consultation->delete();

        return response()->noContent();
    }

    public function sign(Request $request, Consultation $consultation)
    {
        if (!$this->canAccess($request, $consultation)) {
            return response()->json(['message' => 'Consultation introuvable.'], 404);
        }
        if ($request->user()->role?->name !== 'DOCTOR' || (int) $consultation->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Seul le médecin assigné peut signer cette consultation.'], 403);
        }
        if ($consultation->status !== 'COMPLETED') {
            return response()->json(['message' => 'Terminez la consultation avant de la signer.'], 422);
        }
        $this->assertCompletable($consultation);

        DB::transaction(function () use ($consultation, $request): void {
            $signedAt = now();
            $signatureContent = implode('|', [
                $consultation->id,
                $consultation->clinic_id,
                $consultation->patient_id,
                $consultation->doctor_id,
                $consultation->consultation_datetime->toIso8601String(),
                $signedAt->toIso8601String(),
            ]);

            $consultation->digitalSignatures()->create([
                'user_id' => $request->user()->id,
                'signature_hash' => hash_hmac('sha256', $signatureContent, (string) config('app.key')),
                'signed_at' => $signedAt,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $consultation->update(['status' => 'SIGNED']);
        });

        return response()->json(['data' => $consultation->fresh()->load([...self::RELATIONS, 'digitalSignatures'])]);
    }

    /** ICD-10 code search for the diagnosis field. */
    public function cim10(Request $request)
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['search'] ?? ''));

        $codes = DB::table('cim10_codes')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('code', 'like', strtoupper($search).'%')
                ->orWhere('label', 'like', "%{$search}%")))
            ->orderByRaw('CASE WHEN code LIKE ? THEN 0 ELSE 1 END', [strtoupper($search).'%'])
            ->orderBy('code')
            ->limit(20)
            ->get(['code', 'label', 'chapter']);

        return response()->json(['data' => $codes]);
    }

    /** @return array<string, array<int, mixed>> */
    private function contentRules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sections' => ['sometimes', 'array', 'max:8'],
            'sections.*.section_number' => ['required_with:sections', 'integer', 'between:1,8', 'distinct'],
            'sections.*.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sections.*.content' => ['present', 'nullable', 'string', 'max:10000'],
            'diagnoses' => ['sometimes', 'array', 'max:10'],
            'diagnoses.*.cim10_code' => ['required', 'string', 'max:10'],
            'diagnoses.*.cim10_description' => ['required', 'string', 'max:500'],
            'diagnoses.*.is_primary' => ['sometimes', 'boolean'],
            'diagnoses.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function saveContent(Consultation $consultation, array $validated): void
    {
        if (array_key_exists('notes', $validated)) {
            $validated['sections'] = [...($validated['sections'] ?? []), ['section_number' => 1, 'content' => $validated['notes']]];
        }
        foreach ($validated['sections'] ?? [] as $section) {
            $number = (int) $section['section_number'];
            if (trim((string) ($section['content'] ?? '')) === '') {
                $consultation->sections()->where('section_number', $number)->delete();
                continue;
            }
            $consultation->sections()->updateOrCreate(
                ['section_number' => $number],
                ['title' => $section['title'] ?? Consultation::SECTIONS[$number], 'content' => $section['content']],
            );
        }

        if (array_key_exists('diagnosis', $validated) && !array_key_exists('diagnoses', $validated)) {
            $validated['diagnoses'] = $validated['diagnosis'] === null || $validated['diagnosis'] === ''
                ? []
                : [['cim10_code' => 'NON_CODE', 'cim10_description' => $validated['diagnosis'], 'is_primary' => true]];
        }
        if (array_key_exists('diagnoses', $validated)) {
            $diagnoses = collect($validated['diagnoses'])->map(fn (array $diagnosis) => [
                ...$diagnosis,
                'cim10_code' => strtoupper(trim($diagnosis['cim10_code'])),
                'is_primary' => (bool) ($diagnosis['is_primary'] ?? false),
            ]);
            $unknown = $diagnoses->pluck('cim10_code')
                ->reject(fn (string $code) => $code === 'NON_CODE')
                ->diff(DB::table('cim10_codes')->whereIn('code', $diagnoses->pluck('cim10_code'))->pluck('code'));
            if ($unknown->isNotEmpty()) {
                throw ValidationException::withMessages(['diagnoses' => 'Code CIM-10 inconnu : '.$unknown->implode(', ').'.']);
            }
            if ($diagnoses->isNotEmpty() && $diagnoses->where('is_primary', true)->count() !== 1) {
                throw ValidationException::withMessages(['diagnoses' => 'Indiquez un seul diagnostic principal.']);
            }
            $consultation->diagnostics()->delete();
            $consultation->diagnostics()->createMany($diagnoses->map(fn (array $diagnosis) => [
                'cim10_code' => $diagnosis['cim10_code'],
                'cim10_description' => $diagnosis['cim10_description'],
                'is_primary' => $diagnosis['is_primary'],
                'notes' => $diagnosis['notes'] ?? null,
            ])->all());
        }
    }

    /** A consultation can only be closed with a reason for visit and at least one diagnosis. */
    private function assertCompletable(Consultation $consultation): void
    {
        $errors = [];
        if (!$consultation->sections()->where('section_number', 1)->exists()) {
            $errors['sections'] = 'Le motif de consultation est obligatoire pour terminer la consultation.';
        }
        if (!$consultation->diagnostics()->exists()) {
            $errors['diagnoses'] = 'Au moins un diagnostic est obligatoire pour terminer la consultation.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function canAccess(Request $request, Consultation $consultation): bool
    {
        $clinicId = $this->clinicId($request);
        $isPatientOutsideOwnCase = $request->user()->role?->name === 'PATIENT'
            && ((int) $consultation->patient_id !== (int) ($request->user()->patient?->id ?? 0)
                || $consultation->status !== 'SIGNED');
        $isDoctorOutsideOwnCase = $request->user()->role?->name === 'DOCTOR'
            && (int) $consultation->doctor_id !== (int) $request->user()->id;

        return !$isDoctorOutsideOwnCase
            && !$isPatientOutsideOwnCase
            && ($clinicId === null || (int) $consultation->clinic_id === $clinicId);
    }

    private function clinicId(Request $request): ?int
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return $clinicId === null ? null : (int) $clinicId;
    }
}
