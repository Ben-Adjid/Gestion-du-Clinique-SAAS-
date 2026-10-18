<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    use BelongsToClinic;

    protected $table = 'payroll';

    public const STATUSES = [
        'CALCULATED' => 'Calculée',
        'APPROVED' => 'Validée',
        'PAID' => 'Payée',
    ];

    protected $fillable = [
        'clinic_id', 'employee_id', 'month', 'year', 'worked_days', 'unpaid_days', 'base_salary', 'overtime_hours',
        'overtime_amount', 'absence_deduction', 'bonus', 'gross_salary', 'cotisation_deduction', 'tax_deduction',
        'other_deductions', 'net_salary', 'status', 'approved_by', 'approved_at', 'paid_by', 'paid_datetime',
        'payment_method', 'payment_reference',
    ];

    protected $casts = [
        'base_salary' => 'float',
        'overtime_hours' => 'float',
        'overtime_amount' => 'float',
        'absence_deduction' => 'float',
        'bonus' => 'float',
        'gross_salary' => 'float',
        'cotisation_deduction' => 'float',
        'tax_deduction' => 'float',
        'other_deductions' => 'float',
        'net_salary' => 'float',
        'worked_days' => 'float',
        'unpaid_days' => 'float',
        'approved_at' => 'datetime',
        'paid_datetime' => 'datetime',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
