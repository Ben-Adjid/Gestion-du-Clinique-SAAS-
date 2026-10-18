<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Payroll;
use App\Services\HumanResources;
use Illuminate\Http\Request;

/** "Mon espace" for every staff member: clocking, leave balance and payslips. */
class StaffSpaceController extends Controller
{
    public function show(Request $request)
    {
        $employee = Employee::query()->where('user_id', $request->user()->id)->first();
        if (!$employee) {
            return response()->json(['data' => null, 'message' => 'Aucun dossier du personnel n’est lié à votre compte.']);
        }

        return response()->json(['data' => [
            'employee' => $employee->only(['id', 'employee_number', 'first_name', 'last_name', 'position', 'department', 'contract_type', 'contract_end_date', 'hire_date', 'status', 'annual_leave_days']),
            'today' => Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', today())->first(),
            'month_attendance' => Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', '>=', now()->startOfMonth())->orderByDesc('work_date')->get(),
            'leave_balance' => HumanResources::leaveBalance($employee),
            'leaves' => Leave::query()->where('employee_id', $employee->id)->with('reviewer:id,name')->orderByDesc('start_date')->limit(10)->get(),
            'payslips' => Payroll::query()->where('employee_id', $employee->id)->whereIn('status', ['APPROVED', 'PAID'])->orderByDesc('year')->orderByDesc('month')->limit(12)->get(['id', 'month', 'year', 'net_salary', 'status', 'paid_datetime']),
            'settings' => array_intersect_key(HumanResources::settings($employee->clinic_id), array_flip(['work_start', 'late_tolerance_minutes'])),
        ]]);
    }
}
