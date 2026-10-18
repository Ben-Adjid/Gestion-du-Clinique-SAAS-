<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\UserNotification;
use App\Services\HumanResources;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Monthly payroll: HR calculates and adjusts (overtime, bonus, other deductions),
 * the clinic director approves, then HR records the payment. Approved payslips are
 * frozen and visible to the employee.
 */
class PayrollController extends Controller
{
    private const MONTHS = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    public function index(Request $request)
    {
        $clinicId = $this->clinicId($request);
        [$year, $month] = $this->period($request);

        $payrolls = Payroll::query()
            ->where('clinic_id', $clinicId)
            ->where('year', $year)
            ->where('month', $month)
            ->with('employee:id,employee_number,first_name,last_name,position,payment_method,payment_account')
            ->get()
            ->sortBy(fn (Payroll $payroll) => $payroll->employee?->last_name)
            ->values();

        return response()->json([
            'data' => $payrolls,
            'period' => ['year' => $year, 'month' => $month, 'label' => self::MONTHS[$month].' '.$year],
            'totals' => [
                'gross' => round($payrolls->sum('gross_salary')),
                'cnps' => round($payrolls->sum('cotisation_deduction')),
                'tax' => round($payrolls->sum('tax_deduction')),
                'net' => round($payrolls->sum('net_salary')),
                'by_status' => $payrolls->countBy('status'),
            ],
            'settings' => HumanResources::settings($clinicId),
        ]);
    }

    public function generate(Request $request)
    {
        $clinicId = $this->clinicId($request);
        [$year, $month] = $this->period($request);
        if (Carbon::create($year, $month, 1)->isAfter(now()->startOfMonth())) {
            return response()->json(['message' => 'La paie d’un mois futur ne peut pas être calculée.'], 422);
        }
        $counters = HumanResources::generateMonth($clinicId, $year, $month);

        return response()->json([
            'data' => $counters,
            'message' => "Paie de ".self::MONTHS[$month]." {$year} : {$counters['created']} bulletin(s) créé(s), {$counters['updated']} recalculé(s)".($counters['locked'] ? ", {$counters['locked']} déjà validé(s) non modifié(s)" : '').'.',
        ]);
    }

    public function update(Request $request, Payroll $payroll)
    {
        if ((int) $payroll->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Bulletin introuvable.'], 404);
        }
        if ($payroll->status !== 'CALCULATED') {
            return response()->json(['message' => 'Un bulletin validé ne peut plus être modifié.'], 422);
        }
        $validated = $request->validate([
            'overtime_hours' => ['required', 'numeric', 'min:0', 'max:200'],
            'bonus' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'other_deductions' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);
        $payroll->update(HumanResources::computePayroll($payroll->employee, $payroll->year, $payroll->month, $validated));

        return response()->json(['data' => $payroll->fresh()->load('employee:id,employee_number,first_name,last_name,position'), 'message' => 'Bulletin recalculé.']);
    }

    /** Approval by the clinic director, for one payslip or the whole month. */
    public function approve(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'payroll_ids' => ['required', 'array', 'min:1'],
            'payroll_ids.*' => ['integer', Rule::exists('payroll', 'id')->where('clinic_id', $clinicId)->where('status', 'CALCULATED')],
        ], ['payroll_ids.*.exists' => 'Seuls les bulletins calculés et non encore validés peuvent être validés.']);

        $count = DB::transaction(function () use ($validated, $request) {
            $payrolls = Payroll::query()->whereIn('id', $validated['payroll_ids'])->lockForUpdate()->get();
            foreach ($payrolls as $payroll) {
                $payroll->update(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
                if ($payroll->employee?->user_id) {
                    UserNotification::create([
                        'clinic_id' => $payroll->clinic_id,
                        'user_id' => $payroll->employee->user_id,
                        'type' => 'PAYSLIP',
                        'title' => 'Bulletin de paie disponible',
                        'message' => 'Votre bulletin de '.self::MONTHS[$payroll->month]." {$payroll->year} est disponible dans votre espace.",
                    ]);
                }
            }

            return $payrolls->count();
        });

        return response()->json(['message' => "{$count} bulletin(s) validé(s)."]);
    }

    public function pay(Request $request, Payroll $payroll)
    {
        if ((int) $payroll->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Bulletin introuvable.'], 404);
        }
        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(['CASH', 'MOBILE_MONEY', 'BANK_TRANSFER'])],
            'payment_reference' => ['nullable', 'required_unless:payment_method,CASH', 'string', 'max:100'],
        ], ['payment_reference.required_unless' => 'La référence du virement ou de la transaction est obligatoire.']);
        if ($payroll->status !== 'APPROVED') {
            return response()->json(['message' => 'Le bulletin doit être validé par la direction avant le paiement.'], 422);
        }
        $payroll->update([...$validated, 'status' => 'PAID', 'paid_datetime' => now(), 'paid_by' => $request->user()->id]);

        return response()->json(['data' => $payroll->fresh(), 'message' => 'Paiement du salaire enregistré.']);
    }

    /** Payslip: HR, the director, or the employee concerned (once approved). */
    public function show(Request $request, Payroll $payroll)
    {
        if ((int) $payroll->clinic_id !== $this->clinicId($request)) {
            return response()->json(['message' => 'Bulletin introuvable.'], 404);
        }
        $permissions = \App\Support\ModuleAccess::effectivePermissions($request->user());
        $isHr = array_intersect(['manage:payroll', 'approve:payroll'], $permissions) !== [];
        $isOwner = (int) $payroll->employee?->user_id === (int) $request->user()->id && in_array($payroll->status, ['APPROVED', 'PAID'], true);
        if (!$isHr && !$isOwner) {
            return response()->json(['message' => 'Bulletin introuvable.'], 404);
        }

        return response()->json(['data' => [
            ...$payroll->load(['employee', 'approver:id,name'])->toArray(),
            'period_label' => self::MONTHS[$payroll->month].' '.$payroll->year,
            'settings' => HumanResources::settings($payroll->clinic_id),
            'clinic' => DB::table('clinics')->where('id', $payroll->clinic_id)->first(['name', 'city', 'country', 'admin_phone', 'admin_email']),
        ]]);
    }

    /** @return array{0: int, 1: int} */
    private function period(Request $request): array
    {
        $validated = $request->validate(['month' => ['sometimes', 'date_format:Y-m']]);
        $date = isset($validated['month']) ? Carbon::createFromFormat('Y-m-d', $validated['month'].'-01') : now()->startOfMonth();

        return [(int) $date->year, (int) $date->month];
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
