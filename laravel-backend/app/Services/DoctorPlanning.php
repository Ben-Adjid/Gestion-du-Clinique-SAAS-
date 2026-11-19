<?php

namespace App\Services;

use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Who is on duty, present or unavailable each day, and the rule that protects bookings:
 * once the planning of a day is published, a doctor who is not on it cannot receive an
 * appointment that day.
 */
final class DoctorPlanning
{
    /** Status of a doctor on a date, or null when no planning has been published for that date. */
    public function statusFor(int $clinicId, int $doctorId, string $date): ?string
    {
        if (!$this->isPublished($clinicId, $date)) {
            return null;
        }

        return DoctorSchedule::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->where('doctor_id', $doctorId)->whereDate('work_date', $date)
            ->value('status') ?? 'NOT_SCHEDULED';
    }

    public function isPublished(int $clinicId, string $date): bool
    {
        return DoctorSchedule::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereDate('work_date', $date)->exists();
    }

    /** Refuses the booking when the planning is published and the doctor is not on it. */
    public function assertBookable(int $clinicId, int $doctorId, string $date): void
    {
        $status = $this->statusFor($clinicId, $doctorId, $date);
        if ($status === null || in_array($status, DoctorSchedule::BOOKABLE, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'doctor_id' => 'Ce médecin n’est pas programmé le '.Carbon::parse($date)->format('d/m/Y').'. Choisissez un autre médecin ou une autre date.',
        ]);
    }

    /**
     * Dashboard of the day and the next day: doctors on call, present and unavailable.
     *
     * @return array<string, array{date: string, published: bool, on_call: list<array<string, mixed>>, present: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}>
     */
    public function board(int $clinicId, Carbon $today): array
    {
        $board = [];
        foreach (['today' => $today, 'tomorrow' => $today->copy()->addDay()] as $key => $day) {
            $date = $day->toDateString();
            $rows = DoctorSchedule::query()->withoutGlobalScopes()
                ->where('clinic_id', $clinicId)->whereDate('work_date', $date)
                ->with(['doctor:id,name'])
                ->get();
            $profiles = DoctorProfile::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
                ->whereIn('user_id', $rows->pluck('doctor_id'))->get()->keyBy('user_id');

            $entry = fn (DoctorSchedule $row) => [
                'doctor_id' => $row->doctor_id,
                'name' => $row->doctor?->name,
                'specialty' => $profiles->get($row->doctor_id)?->specialty,
                'employment' => $profiles->get($row->doctor_id)?->employment ?? 'PERMANENT',
                'note' => $row->note,
            ];

            $board[$key] = [
                'date' => $date,
                'published' => $rows->isNotEmpty(),
                'on_call' => $rows->where('status', 'ON_CALL')->map($entry)->values()->all(),
                'present' => $rows->where('status', 'PRESENT')->map($entry)->values()->all(),
                'unavailable' => $rows->where('status', 'UNAVAILABLE')->map($entry)->values()->all(),
            ];
        }

        return $board;
    }

    /** Doctors of the clinic, with their specialty and their status on a date. */
    public function doctorsFor(int $clinicId, ?string $date, ?string $specialty): array
    {
        $profiles = DoctorProfile::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->get()->keyBy('user_id');
        $doctors = User::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->where('status', 'ACTIVE')
            ->whereHas('role', fn ($query) => $query->where('name', 'DOCTOR'))
            ->orderBy('name')->get(['id', 'name']);

        return $doctors
            ->map(function (User $doctor) use ($clinicId, $date, $profiles) {
                $profile = $profiles->get($doctor->id);
                $status = $date ? $this->statusFor($clinicId, $doctor->id, $date) : null;

                return [
                    'id' => $doctor->id,
                    'name' => $doctor->name,
                    'specialty' => $profile?->specialty ?? 'Médecine générale',
                    'employment' => $profile?->employment ?? 'PERMANENT',
                    'planning_status' => $status,
                    'bookable' => $status === null || in_array($status, DoctorSchedule::BOOKABLE, true),
                ];
            })
            ->filter(fn (array $doctor) => $specialty === null || $specialty === '' || $doctor['specialty'] === $specialty)
            ->values()
            ->all();
    }
}
