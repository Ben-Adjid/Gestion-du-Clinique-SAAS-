<?php

namespace App\Services;

use App\Models\AbsenceRequest;
use App\Models\Attendance;
use App\Models\Clinic;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Carbon;

/**
 * Runs at 10:00: any working day on which an employee has neither clocked in, nor an approved
 * leave, nor an approved justification, is reported to HR and to the director.
 */
final class AbsenceAlerts
{
    public const TYPE = 'ABSENCE_ALERT';

    /** @return array{alerts: int, clinics: int} */
    public function run(?Carbon $day = null): array
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $counters = ['alerts' => 0, 'clinics' => 0];
        if ($day->isSunday()) {
            return $counters;
        }

        Clinic::query()->where('status', 'ACTIVE')->each(function (Clinic $clinic) use ($day, &$counters) {
            $counters['clinics']++;
            $recipients = User::query()->withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)->where('status', 'ACTIVE')
                ->whereHas('role', fn ($query) => $query->whereIn('name', ['HR_MANAGER', 'ADMIN_CLINIC']))
                ->pluck('id');

            Employee::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
                ->where('status', '!=', 'TERMINATED')->each(function (Employee $employee) use ($clinic, $day, $recipients, &$counters) {
                    if (!$this->isMissing($employee, $day)) {
                        return;
                    }
                    $name = trim($employee->first_name.' '.$employee->last_name);
                    foreach ($recipients as $userId) {
                        $already = UserNotification::query()->where('user_id', $userId)->where('type', self::TYPE)
                            ->where('title', "Absence sans pointage : {$name}")->whereDate('created_at', now()->toDateString())->exists();
                        if ($already) {
                            continue;
                        }
                        UserNotification::query()->create([
                            'clinic_id' => $clinic->id,
                            'user_id' => $userId,
                            'type' => self::TYPE,
                            'title' => "Absence sans pointage : {$name}",
                            'message' => 'Aucun pointage, congé approuvé ni justificatif pour le '.$day->format('d/m/Y').'. Vérifiez la situation.',
                        ]);
                        $counters['alerts']++;
                    }
                });
        });

        return $counters;
    }

    private function isMissing(Employee $employee, Carbon $day): bool
    {
        $date = $day->toDateString();
        if ($employee->hire_date && $employee->hire_date > $date) {
            return false;
        }
        if ($employee->end_date && $employee->end_date < $date) {
            return false;
        }

        return !Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $date)->exists()
            && !Leave::query()->where('employee_id', $employee->id)->where('status', 'APPROVED')
                ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->exists()
            && !AbsenceRequest::query()->where('employee_id', $employee->id)->where('status', 'APPROVED')
                ->whereDate('work_date', $date)->exists();
    }
}
