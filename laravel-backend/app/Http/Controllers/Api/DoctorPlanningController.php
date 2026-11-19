<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use App\Services\DoctorPlanning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Planning of doctors (present, on call, unavailable) and their specialty / employment. */
class DoctorPlanningController extends Controller
{
    public function __construct(private readonly DoctorPlanning $planning)
    {
    }

    /** "Médecins de garde aujourd'hui" and "présents demain". */
    public function board(Request $request): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;

        return response()->json(['data' => $this->planning->board($clinicId, now())]);
    }

    /** Sets the status of several doctors on several days in one go (upsert). */
    public function update(Request $request): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;
        $validated = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.doctor_id' => ['required', 'integer'],
            'entries.*.work_date' => ['required', 'date_format:Y-m-d'],
            'entries.*.status' => ['required', Rule::in(DoctorSchedule::STATUSES)],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $doctors = User::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
            ->whereHas('role', fn ($query) => $query->where('name', 'DOCTOR'))
            ->pluck('id')->flip();

        DB::transaction(function () use ($validated, $clinicId, $doctors, $request) {
            foreach ($validated['entries'] as $entry) {
                abort_unless($doctors->has($entry['doctor_id']), 422, 'Médecin inconnu dans cette clinique.');
                DoctorSchedule::query()->updateOrCreate(
                    ['doctor_id' => $entry['doctor_id'], 'work_date' => $entry['work_date']],
                    [
                        'clinic_id' => $clinicId,
                        'status' => $entry['status'],
                        'note' => $entry['note'] ?? null,
                        'recorded_by' => $request->user()->id,
                    ],
                );
            }
        });

        return response()->json(['message' => 'Planning enregistré.', 'data' => $this->planning->board($clinicId, now())]);
    }

    /** Specialty and employment status of one doctor. */
    public function updateProfile(Request $request, User $doctor): JsonResponse
    {
        abort_unless($doctor->clinic_id === (int) $request->user()->clinic_id && $doctor->role?->name === 'DOCTOR', 404);
        $validated = $request->validate([
            'specialty' => ['required', 'string', 'max:80'],
            'employment' => ['required', Rule::in(DoctorProfile::EMPLOYMENTS)],
            'license_number' => ['nullable', 'string', 'max:40'],
        ]);

        $profile = DoctorProfile::query()->updateOrCreate(
            ['user_id' => $doctor->id],
            ['clinic_id' => $doctor->clinic_id, 'specialty' => trim($validated['specialty']), 'employment' => $validated['employment'], 'license_number' => $validated['license_number'] ?? null],
        );

        return response()->json(['data' => $profile, 'message' => 'Profil du médecin mis à jour.']);
    }

    /** Specialties in use in the clinic, with the platform's standard list. */
    public function specialties(Request $request): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;
        $used = DoctorProfile::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->distinct()->orderBy('specialty')->pluck('specialty');
        $standard = ['Médecine générale', 'Cardiologie', 'Pédiatrie', 'Gynécologie-obstétrique', 'Radiologie', 'Chirurgie générale', 'Anesthésie-réanimation', 'Dermatologie', 'Ophtalmologie', 'Psychiatrie'];

        return response()->json(['data' => collect($standard)->merge($used)->unique()->values()]);
    }
}
