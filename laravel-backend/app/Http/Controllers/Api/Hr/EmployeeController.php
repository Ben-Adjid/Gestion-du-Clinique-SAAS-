<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\HumanResources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(array_keys(Employee::STATUSES))],
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $employees = Employee::query()
            ->where('clinic_id', $clinicId)
            ->with('user:id,email,role_id', 'user.role:id,name,display_name')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status), fn ($query) => $query->where('status', '!=', 'TERMINATED'))
            ->when($filters['department'] ?? null, fn ($query, $department) => $query->where('department', $department))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('position', 'like', "%{$search}%")
                ->orWhere('employee_number', 'like', "%{$search}%")))
            ->orderBy('last_name')
            ->paginate(25);

        $onLeaveToday = DB::table('leaves')
            ->where('clinic_id', $clinicId)
            ->where('status', 'APPROVED')
            ->whereNull('cancelled_at')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->pluck('employee_id')
            ->all();
        $employees->getCollection()->each(fn (Employee $employee) => $employee->setAttribute('on_leave_today', in_array($employee->id, $onLeaveToday, true)));

        return response()->json([...$employees->toArray(), 'departments' => Employee::query()->where('clinic_id', $clinicId)->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')]);
    }

    /** Clinic accounts that have no staff file yet. */
    public function availableUsers(Request $request)
    {
        $clinicId = $this->clinicId($request);

        return response()->json(['data' => User::query()
            ->where('clinic_id', $clinicId)
            ->whereHas('role', fn ($query) => $query->whereNotIn('name', ['PATIENT', 'SUPER_ADMIN']))
            ->whereNotIn('id', Employee::withTrashed()->where('clinic_id', $clinicId)->whereNotNull('user_id')->select('user_id'))
            ->with('role:id,display_name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role_id'])]);
    }

    public function show(Request $request, Employee $employee)
    {
        if ((int) $employee->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Salarié introuvable.'], 404);
        }
        $employee->load(['user:id,name,email,role_id', 'user.role:id,display_name']);

        return response()->json(['data' => [
            ...$employee->toArray(),
            'leave_balance' => HumanResources::leaveBalance($employee),
            'recent_attendance' => $employee->attendance()->orderByDesc('work_date')->limit(10)->get(),
            'leaves' => $employee->leaves()->latest('start_date')->limit(10)->get(),
            'payrolls' => $employee->payrolls()->orderByDesc('year')->orderByDesc('month')->limit(12)->get(['id', 'month', 'year', 'gross_salary', 'net_salary', 'status']),
        ]]);
    }

    public function store(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate($this->rules($clinicId));

        $employee = DB::transaction(function () use ($validated, $clinicId) {
            $last = Employee::withTrashed()->where('clinic_id', $clinicId)->lockForUpdate()->pluck('employee_number')
                ->map(fn ($number) => (int) preg_replace('/\D/', '', (string) $number))->max() ?? 0;

            return Employee::create([
                ...$validated,
                'clinic_id' => $clinicId,
                'employee_number' => 'MAT-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT),
                'status' => 'ACTIVE',
            ]);
        });

        return response()->json(['data' => $employee, 'message' => "Dossier {$employee->employee_number} créé."], 201);
    }

    public function update(Request $request, Employee $employee)
    {
        if ((int) $employee->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Salarié introuvable.'], 404);
        }
        if ($employee->status === 'TERMINATED') {
            return response()->json(['message' => 'Le dossier d’un salarié sorti des effectifs ne peut plus être modifié.'], 422);
        }
        $rules = collect($this->rules($employee->clinic_id, $employee))->map(fn (array $rule) => ['sometimes', ...$rule])->all();
        $validated = $request->validate([...$rules, 'status' => ['sometimes', Rule::in(['ACTIVE', 'SUSPENDED'])]]);
        $employee->update($validated);

        return response()->json(['data' => $employee->fresh(), 'message' => 'Dossier mis à jour.']);
    }

    /** End of contract: the file is kept (payslips, history) but the employee leaves the staff list. */
    public function terminate(Request $request, Employee $employee)
    {
        if ((int) $employee->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Salarié introuvable.'], 404);
        }
        $validated = $request->validate([
            'end_date' => ['required', 'date', 'after_or_equal:'.$employee->hire_date->toDateString()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        if ($employee->status === 'TERMINATED') {
            return response()->json(['message' => 'Ce salarié est déjà sorti des effectifs.'], 422);
        }
        $employee->update([
            'status' => 'TERMINATED',
            'end_date' => $validated['end_date'],
            'metadata' => [...($employee->metadata ?? []), 'termination_reason' => $validated['reason']],
        ]);
        if ($employee->user) {
            $employee->user->update(['status' => 'INACTIVE']);
            $employee->user->tokens()->delete();
        }

        return response()->json(['data' => $employee->fresh(), 'message' => 'Sortie enregistrée. Le compte d’accès a été désactivé.']);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(int $clinicId, ?Employee $employee = null): array
    {
        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('clinic_id', $clinicId), Rule::unique('employees', 'user_id')->ignore($employee?->id)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'position' => ['required', 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:100'],
            'contract_type' => ['required', Rule::in(array_keys(Employee::CONTRACT_TYPES))],
            'contract_end_date' => [Rule::requiredIf(in_array(request('contract_type'), ['CDD', 'STAGE'], true)), 'nullable', 'date', 'after:hire_date'],
            'hire_date' => ['required', 'date', 'before_or_equal:+3 months'],
            'base_salary' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'national_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'cnps_number' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['required', Rule::in(['CASH', 'MOBILE_MONEY', 'BANK_TRANSFER'])],
            'payment_account' => [Rule::requiredIf(in_array(request('payment_method'), ['MOBILE_MONEY', 'BANK_TRANSFER'], true)), 'nullable', 'string', 'max:100'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'annual_leave_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
        ];
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
