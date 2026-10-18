<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\MedicalCertificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Medical certificates are numbered and signed when issued; they can never be
 * edited afterwards, only cancelled with a reason (the original stays on file).
 */
class MedicalCertificateController extends Controller
{
    private const RELATIONS = [
        'patient:id,first_name,last_name,patient_unique_id,date_of_birth,gender',
        'doctor:id,name',
        'clinic:id,name,city,country,admin_phone,admin_email',
    ];

    public function index(Request $request)
    {
        $filters = $request->validate(['patient_id' => ['sometimes', 'nullable', 'integer']]);
        $user = $request->user();

        $certificates = MedicalCertificate::query()
            ->where('clinic_id', $this->clinicId($request))
            ->when($user->role?->name === 'PATIENT', fn ($query) => $query
                ->where('patient_id', $user->patient?->id ?? 0)
                ->whereNull('cancelled_at'))
            ->when($user->role?->name === 'DOCTOR', fn ($query) => $query->where('doctor_id', $user->id))
            ->when($filters['patient_id'] ?? null, fn ($query, $patientId) => $query->where('patient_id', $patientId))
            ->with(self::RELATIONS)
            ->latest('issued_at')
            ->paginate(30);

        return response()->json($certificates);
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)->whereNull('deleted_at')],
            'consultation_id' => ['nullable', 'integer', Rule::exists('consultations', 'id')->where('clinic_id', $clinicId)],
            'type' => ['required', Rule::in(array_keys(MedicalCertificate::TYPES))],
            'start_date' => [Rule::requiredIf(in_array($request->input('type'), ['WORK_STOPPAGE', 'SCHOOL_ABSENCE'], true)), 'nullable', 'date'],
            'end_date' => [Rule::requiredIf(in_array($request->input('type'), ['WORK_STOPPAGE', 'SCHOOL_ABSENCE'], true)), 'nullable', 'date', 'after_or_equal:start_date'],
            'content' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'start_date.required' => 'La date de début est obligatoire pour un arrêt ou une absence.',
            'end_date.required' => 'La date de fin est obligatoire pour un arrêt ou une absence.',
            'end_date.after_or_equal' => 'La date de fin doit être après la date de début.',
        ]);

        if ($request->user()->role?->name !== 'DOCTOR') {
            return response()->json(['message' => 'Seul un médecin peut délivrer un certificat médical.'], 403);
        }
        if (!empty($validated['start_date']) && !empty($validated['end_date'])
            && \Carbon\Carbon::parse($validated['start_date'])->diffInDays(\Carbon\Carbon::parse($validated['end_date'])) > 365) {
            return response()->json(['message' => 'La durée d’un certificat ne peut pas dépasser un an.'], 422);
        }
        if (!empty($validated['consultation_id'])) {
            $consultation = Consultation::findOrFail($validated['consultation_id']);
            if ((int) $consultation->patient_id !== (int) $validated['patient_id'] || (int) $consultation->doctor_id !== (int) $request->user()->id) {
                return response()->json(['message' => 'La consultation ne correspond pas à ce patient ou à ce médecin.'], 422);
            }
        }

        $certificate = DB::transaction(function () use ($validated, $clinicId, $request) {
            $clinic = Clinic::whereKey($clinicId)->lockForUpdate()->firstOrFail();
            $prefix = "CM-{$clinic->code}-".now()->year.'-';
            $last = MedicalCertificate::query()
                ->where('clinic_id', $clinicId)
                ->where('number', 'like', $prefix.'%')
                ->pluck('number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
                ->max() ?? 0;
            $number = $prefix.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
            $issuedAt = now();

            return MedicalCertificate::create([
                ...$validated,
                'clinic_id' => $clinicId,
                'doctor_id' => $request->user()->id,
                'number' => $number,
                'issued_at' => $issuedAt,
                'signature_hash' => hash_hmac('sha256', implode('|', [
                    $number, $clinicId, $validated['patient_id'], $request->user()->id, $validated['type'],
                    $validated['start_date'] ?? '', $validated['end_date'] ?? '', $validated['content'], $issuedAt->toIso8601String(),
                ]), (string) config('app.key')),
            ]);
        });

        return response()->json(['data' => $certificate->load(self::RELATIONS), 'message' => 'Certificat délivré.'], 201);
    }

    public function show(Request $request, MedicalCertificate $certificate)
    {
        if (!$this->canSee($request, $certificate)) {
            return response()->json(['message' => 'Certificat introuvable.'], 404);
        }

        return response()->json(['data' => $certificate->load(self::RELATIONS)]);
    }

    public function cancel(Request $request, MedicalCertificate $certificate)
    {
        if (!$this->canSee($request, $certificate) || (int) $certificate->doctor_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Seul le médecin signataire peut annuler ce certificat.'], 403);
        }
        if ($certificate->cancelled_at !== null) {
            return response()->json(['message' => 'Ce certificat est déjà annulé.'], 422);
        }
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $certificate->update(['cancelled_at' => now(), 'cancellation_reason' => $validated['reason']]);

        return response()->json(['data' => $certificate->fresh()->load(self::RELATIONS), 'message' => 'Certificat annulé.']);
    }

    private function canSee(Request $request, MedicalCertificate $certificate): bool
    {
        $user = $request->user();
        if ((int) $certificate->clinic_id !== $this->clinicId($request)) {
            return false;
        }

        return match ($user->role?->name) {
            'PATIENT' => (int) $certificate->patient_id === (int) ($user->patient?->id ?? 0) && $certificate->cancelled_at === null,
            'DOCTOR' => (int) $certificate->doctor_id === (int) $user->id,
            default => true,
        };
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
