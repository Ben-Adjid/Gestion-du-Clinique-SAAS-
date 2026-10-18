<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Patient;
use App\Support\ModuleAccess;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public const MARITAL_STATUSES = ['SINGLE', 'MARRIED', 'DIVORCED', 'WIDOWED'];
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role?->name === 'PATIENT') {
            $patient = $user->patient;

            return response()->json(['data' => $patient ? [$patient] : [], 'current_page' => 1, 'last_page' => 1, 'total' => $patient ? 1 : 0]);
        }

        $isUnlinkedLookup = $request->boolean('unlinked');
        if ($isUnlinkedLookup && !in_array($user->role?->name, ['SUPER_ADMIN', 'ADMIN_CLINIC'], true)) {
            return response()->json(['message' => 'Only clinic administrators can select patients for an account.'], 403);
        }
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', Rule::in(['M', 'F', 'OTHER'])],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);
        $clinicId = $request->attributes->get('clinic_id') ?? $user->clinic_id;
        $search = trim((string) ($validated['search'] ?? ''));

        $patients = Patient::query()
            ->where('clinic_id', $clinicId)
            ->when($isUnlinkedLookup, fn ($query) => $query->whereNull('user_id'))
            ->when($search !== '', function ($query) use ($search) {
                $digits = preg_replace('/\D/', '', $search);
                $query->where(function ($inner) use ($search, $digits) {
                    foreach (preg_split('/\s+/', $search) as $term) {
                        $inner->where(fn ($words) => $words
                            ->where('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%"));
                    }
                    $inner->orWhere('patient_unique_id', 'like', "%{$search}%")
                        ->orWhere('national_id', 'like', "%{$search}%");
                    if (strlen($digits) >= 4) {
                        $inner->orWhere(DB::raw("REPLACE(REPLACE(phone, ' ', ''), '+', '')"), 'like', "%{$digits}%");
                    }
                });
            })
            ->when($validated['gender'] ?? null, fn ($query, $gender) => $query->where('gender', $gender))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($validated['per_page'] ?? 25);

        return response()->json($patients);
    }

    /** Possible duplicates of a patient being registered, shown before saving. */
    public function duplicates(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:30'],
            'ignore_id' => ['nullable', 'integer'],
        ]);
        $clinicId = (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);

        return response()->json(['data' => $this->duplicateCandidates($clinicId, $validated, $validated['ignore_id'] ?? null)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            ...$this->identityRules($request, null),
            'contacts' => ['sometimes', 'array', 'max:5'],
            'contacts.*.emergency_contact_name' => ['required', 'string', 'max:255'],
            'contacts.*.emergency_contact_phone' => ['required', 'string', 'max:30'],
            'contacts.*.relationship' => ['nullable', 'string', 'max:100'],
            'allergies' => ['sometimes', 'array', 'max:50'],
            'allergies.*.allergen' => ['required', 'string', 'max:255'],
            'allergies.*.severity' => ['required', Rule::in(['LOW', 'MEDIUM', 'HIGH'])],
            'allergies.*.notes' => ['nullable', 'string', 'max:2000'],
            'medical_history' => ['sometimes', 'array', 'max:50'],
            'medical_history.*.condition' => ['required', 'string', 'max:255'],
            'medical_history.*.start_date' => ['nullable', 'date', 'before_or_equal:today'],
            'medical_history.*.notes' => ['nullable', 'string', 'max:2000'],
            'confirm_not_duplicate' => ['sometimes', 'boolean'],
        ]);

        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;
        if (!$clinicId) {
            return response()->json(['message' => 'Sélectionnez une clinique avant d’enregistrer un patient.'], 422);
        }
        PlanLimits::assertCanAddPatient((int) $clinicId);

        $candidates = $this->duplicateCandidates((int) $clinicId, $validated);
        if ($candidates->isNotEmpty() && !($validated['confirm_not_duplicate'] ?? false)) {
            return response()->json([
                'message' => 'Un dossier semblable existe peut-être déjà. Vérifiez avant de créer un nouveau dossier.',
                'code' => 'POSSIBLE_DUPLICATE',
                'candidates' => $candidates,
            ], 409);
        }

        $patient = DB::transaction(function () use ($validated, $clinicId, $candidates, $request) {
            $patient = Patient::create([
                ...collect($validated)->only($this->identityFields())->all(),
                'clinic_id' => $clinicId,
                'patient_unique_id' => $this->nextUniqueId((int) $clinicId),
            ]);
            foreach (['contacts' => 'emergencyContacts', 'allergies' => 'allergies', 'medical_history' => 'medicalHistory'] as $key => $relation) {
                if (!empty($validated[$key])) {
                    $patient->{$relation}()->createMany($validated[$key]);
                }
            }
            foreach ($candidates as $candidate) {
                DB::table('duplicate_alerts')->insert([
                    'patient_id_1' => $candidate['id'],
                    'patient_id_2' => $patient->id,
                    'similarity_score' => min(0.99, $candidate['similarity'] / 100),
                    'status' => 'PENDING',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $patient;
        });

        return response()->json(['data' => $this->present($request, $patient)], 201);
    }

    public function show(Request $request, Patient $patient)
    {
        if ($request->user()->role?->name === 'PATIENT') {
            if ((int) $patient->user_id !== (int) $request->user()->id) {
                return response()->json(['message' => 'Patient not found'], 404);
            }

            return response()->json(['data' => $this->present($request, $patient)], 200);
        }
        if (!$this->inClinic($request, $patient)) {
            return response()->json(['message' => 'Patient not found'], 404);
        }

        return response()->json(['data' => $this->present($request, $patient)], 200);
    }

    public function update(Request $request, Patient $patient)
    {
        if (!$this->inClinic($request, $patient)) {
            return response()->json(['message' => 'Patient not found'], 404);
        }

        $rules = collect($this->identityRules($request, $patient))
            ->map(fn (array $rule) => ['sometimes', ...array_filter($rule, fn ($item) => $item !== 'required')])
            ->all();
        foreach (['first_name', 'last_name'] as $field) {
            $rules[$field][] = 'filled';
        }
        $validated = $request->validate($rules);

        $patient->update($validated);

        return response()->json(['data' => $this->present($request, $patient->fresh())], 200);
    }

    public function destroy(Request $request, Patient $patient)
    {
        if (!$this->inClinic($request, $patient)) {
            return response()->json(['message' => 'Patient not found'], 404);
        }
        if ($patient->user()->exists()) {
            return response()->json(['message' => 'Remove the linked patient account before deleting this record.'], 409);
        }
        $patient->delete();

        return response()->json(null, 204);
    }

    public function uploadPhoto(Request $request, Patient $patient)
    {
        if (!$this->inClinic($request, $patient)) {
            return response()->json(['message' => 'Patient not found'], 404);
        }
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048']], [
            'photo.max' => 'La photo ne doit pas dépasser 2 Mo.',
            'photo.mimes' => 'Formats acceptés : JPEG, PNG ou WEBP.',
        ]);

        $path = $request->file('photo')->storeAs(
            "patient-photos/{$patient->clinic_id}",
            Str::uuid().'.'.$request->file('photo')->extension(),
            'local',
        );
        if ($patient->photo_path) {
            Storage::disk('local')->delete($patient->photo_path);
        }
        $patient->update(['photo_path' => $path]);

        return response()->json(['data' => $this->present($request, $patient->fresh()), 'message' => 'Photo enregistrée.']);
    }

    public function photo(Request $request, Patient $patient)
    {
        if (!$this->inClinic($request, $patient) || !$patient->photo_path || !Storage::disk('local')->exists($patient->photo_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($patient->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function deletePhoto(Request $request, Patient $patient)
    {
        if (!$this->inClinic($request, $patient)) {
            return response()->json(['message' => 'Patient not found'], 404);
        }
        if ($patient->photo_path) {
            Storage::disk('local')->delete($patient->photo_path);
            $patient->update(['photo_path' => null]);
        }

        return response()->json(['data' => $this->present($request, $patient->fresh()), 'message' => 'Photo supprimée.']);
    }

    /** @return array<string, array<int, mixed>> */
    private function identityRules(Request $request, ?Patient $patient): array
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // Many patients do not know their exact date of birth: it stays optional.
            'gender' => ['nullable', Rule::in(['M', 'F', 'OTHER'])],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'national_id' => ['nullable', 'string', 'max:50', Rule::unique('patients', 'national_id')->where('clinic_id', $clinicId)->whereNull('deleted_at')->ignore($patient?->id)],
            'marital_status' => ['nullable', Rule::in(self::MARITAL_STATUSES)],
            'profession' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'blood_group' => ['nullable', Rule::in(self::BLOOD_GROUPS)],
        ];
    }

    /** @return list<string> */
    private function identityFields(): array
    {
        return ['first_name', 'last_name', 'gender', 'date_of_birth', 'national_id', 'marital_status', 'profession', 'phone', 'email', 'address', 'city', 'blood_group'];
    }

    private function present(Request $request, Patient $patient): array
    {
        $patient->load('emergencyContacts');
        $canReadClinical = in_array('read:patient_clinical_profile', ModuleAccess::effectivePermissions($request->user()), true);
        if ($canReadClinical) {
            $patient->load(['allergies', 'medicalHistory']);
        }

        return [
            ...$patient->toArray(),
            'has_photo' => $patient->photo_path !== null,
            'clinical_profile_visible' => $canReadClinical,
        ];
    }

    private function duplicateCandidates(int $clinicId, array $data, ?int $ignoreId = null): Collection
    {
        $name = $this->normalize($data['first_name'].' '.$data['last_name']);
        $digits = substr(preg_replace('/\D/', '', (string) ($data['phone'] ?? '')), -8);
        $birth = !empty($data['date_of_birth']) ? substr((string) $data['date_of_birth'], 0, 10) : null;
        $lastPrefix = mb_substr(trim($data['last_name']), 0, 3);

        return Patient::query()
            ->where('clinic_id', $clinicId)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($birth, $digits, $lastPrefix) {
                $query->where('last_name', 'like', "{$lastPrefix}%")->orWhere('first_name', 'like', "{$lastPrefix}%");
                if ($birth) {
                    $query->orWhereDate('date_of_birth', $birth);
                }
                if (strlen($digits) >= 8) {
                    $query->orWhere(DB::raw("REPLACE(REPLACE(phone, ' ', ''), '+', '')"), 'like', "%{$digits}");
                }
            })
            ->limit(200)
            ->get()
            ->map(function (Patient $candidate) use ($name, $digits, $birth) {
                $candidateName = $this->normalize($candidate->first_name.' '.$candidate->last_name);
                $reversed = $this->normalize($candidate->last_name.' '.$candidate->first_name);
                similar_text($name, $candidateName, $direct);
                similar_text($name, $reversed, $swapped);
                $similarity = round(max($direct, $swapped), 1);
                $samePhone = strlen($digits) >= 8 && str_ends_with(preg_replace('/\D/', '', (string) $candidate->phone), $digits);
                $sameBirth = $birth !== null && $candidate->date_of_birth?->toDateString() === $birth;

                $reason = match (true) {
                    $samePhone && $similarity >= 60 => 'Même numéro de téléphone et nom proche',
                    $sameBirth && $similarity >= 85 => 'Nom très proche et même date de naissance',
                    $similarity >= 95 => 'Nom identique ou presque',
                    default => null,
                };

                return $reason === null ? null : [
                    'id' => $candidate->id,
                    'patient_unique_id' => $candidate->patient_unique_id,
                    'first_name' => $candidate->first_name,
                    'last_name' => $candidate->last_name,
                    'date_of_birth' => $candidate->date_of_birth?->toDateString(),
                    'phone' => $candidate->phone,
                    'similarity' => $similarity,
                    'reason' => $reason,
                ];
            })
            ->filter()
            ->sortByDesc('similarity')
            ->take(5)
            ->values();
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', '', strtolower($ascii))));
    }

    private function nextUniqueId(int $clinicId): string
    {
        $clinic = Clinic::whereKey($clinicId)->lockForUpdate()->firstOrFail();
        $prefix = "{$clinic->code}-".now()->year.'-';
        $lastSequence = Patient::withTrashed()
            ->where('clinic_id', $clinicId)
            ->where('patient_unique_id', 'like', $prefix.'%')
            ->pluck('patient_unique_id')
            ->map(fn (string $id) => (int) substr($id, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($lastSequence + 1), 6, '0', STR_PAD_LEFT);
    }

    private function inClinic(Request $request, Patient $patient): bool
    {
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;

        return (int) $patient->clinic_id === (int) $clinicId;
    }
}
