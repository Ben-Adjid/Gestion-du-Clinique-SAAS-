<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Clinic;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Payroll;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HR rules shared by attendance, leave and payroll. Rates are clinic settings:
 * the defaults follow common practice in Chad (CNPS employee share 3.5 %, 26
 * working days a month Monday–Saturday, 2 days of paid leave per month) and the
 * income-tax rate is a simplified flat rate to be set by the clinic's accountant.
 */
final class HumanResources
{
    public const DEFAULTS = [
        'work_start' => '08:00',
        'late_tolerance_minutes' => 15,
        'daily_hours' => 8,
        'working_days_per_month' => 26,
        'monthly_hours' => 173.33,
        'overtime_rate' => 1.25,
        'cnps_rate' => 3.5,
        'tax_rate' => 5,
    ];

    public static function settings(int $clinicId): array
    {
        $settings = Clinic::query()->whereKey($clinicId)->value('settings');
        $settings = is_array($settings) ? $settings : (json_decode((string) $settings, true) ?: []);

        return [...self::DEFAULTS, ...($settings['hr'] ?? [])];
    }

    /** Working days between two dates, Sundays excluded. */
    public static function workingDays(Carbon $start, Carbon $end): int
    {
        $days = 0;
        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day->addDay()) {
            if (!$day->isSunday()) {
                $days++;
            }
        }

        return $days;
    }

    /** Paid leave still available this year. */
    public static function leaveBalance(Employee $employee, ?int $year = null): array
    {
        $year ??= (int) now()->year;
        $taken = (int) Leave::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', 'VACATION')
            ->whereIn('status', ['APPROVED', 'PENDING'])
            ->whereNull('cancelled_at')
            ->whereYear('start_date', $year)
            ->sum('days_count');

        return ['entitled' => (int) $employee->annual_leave_days, 'taken_or_requested' => $taken, 'remaining' => max(0, (int) $employee->annual_leave_days - $taken)];
    }

    /** Computes (or recomputes) the payroll of an employee for a month. */
    public static function computePayroll(Employee $employee, int $year, int $month, array $adjustments = []): array
    {
        $settings = self::settings($employee->clinic_id);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $attendance = Attendance::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get();

        $unpaidLeaveDays = Leave::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', 'UNPAID')
            ->where('status', 'APPROVED')
            ->whereNull('cancelled_at')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get()
            ->sum(fn (Leave $leave) => self::workingDays($leave->start_date->max($start), $leave->end_date->min($end)));
        $absentDays = $attendance->where('status', 'ABSENT')->count();
        $unpaidDays = $unpaidLeaveDays + $absentDays;

        $base = (float) $employee->base_salary;
        $dailyRate = $base / (int) $settings['working_days_per_month'];
        $hourlyRate = $base / (float) $settings['monthly_hours'];
        $overtimeHours = $adjustments['overtime_hours'] ?? round($attendance->sum(fn (Attendance $day) => max(0, ($day->worked_hours ?? 0) - (float) $settings['daily_hours'])), 2);
        $bonus = (float) ($adjustments['bonus'] ?? 0);
        $other = (float) ($adjustments['other_deductions'] ?? 0);

        $absenceDeduction = round(min($base, $dailyRate * $unpaidDays));
        $overtimeAmount = round($hourlyRate * (float) $settings['overtime_rate'] * $overtimeHours);
        $gross = round($base - $absenceDeduction + $overtimeAmount + $bonus);
        $cnps = round($gross * (float) $settings['cnps_rate'] / 100);
        $tax = round(($gross - $cnps) * (float) $settings['tax_rate'] / 100);

        return [
            'worked_days' => $attendance->whereIn('status', ['PRESENT', 'LATE'])->count(),
            'unpaid_days' => $unpaidDays,
            'base_salary' => $base,
            'overtime_hours' => $overtimeHours,
            'overtime_amount' => $overtimeAmount,
            'absence_deduction' => $absenceDeduction,
            'bonus' => $bonus,
            'gross_salary' => $gross,
            'cotisation_deduction' => $cnps,
            'tax_deduction' => $tax,
            'other_deductions' => $other,
            'net_salary' => max(0, $gross - $cnps - $tax - $other),
        ];
    }

    /** Creates or refreshes the CALCULATED payrolls of a month; approved and paid ones are never touched. */
    public static function generateMonth(int $clinicId, int $year, int $month): array
    {
        $end = Carbon::create($year, $month, 1)->endOfMonth();
        $start = $end->copy()->startOfMonth();
        $counters = ['created' => 0, 'updated' => 0, 'locked' => 0];

        $employees = Employee::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('hire_date', '<=', $end->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start->toDateString()))
            ->where('base_salary', '>', 0)
            ->get();

        DB::transaction(function () use ($employees, $year, $month, $clinicId, &$counters) {
            foreach ($employees as $employee) {
                $existing = Payroll::query()->where('employee_id', $employee->id)->where('year', $year)->where('month', $month)->first();
                if ($existing && $existing->status !== 'CALCULATED') {
                    $counters['locked']++;
                    continue;
                }
                $values = self::computePayroll($employee, $year, $month, $existing ? [
                    'overtime_hours' => $existing->overtime_hours,
                    'bonus' => $existing->bonus,
                    'other_deductions' => $existing->other_deductions,
                ] : []);
                Payroll::updateOrCreate(
                    ['clinic_id' => $clinicId, 'employee_id' => $employee->id, 'year' => $year, 'month' => $month],
                    [...$values, 'status' => 'CALCULATED'],
                );
                $counters[$existing ? 'updated' : 'created']++;
            }
        });

        return $counters;
    }
}
