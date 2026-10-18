<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    use BelongsToClinic;

    public const TYPES = [
        'VACATION' => 'Congé annuel',
        'SICK' => 'Congé maladie',
        'MATERNITY' => 'Congé maternité',
        'UNPAID' => 'Congé sans solde',
    ];

    protected $fillable = [
        'clinic_id', 'employee_id', 'leave_type', 'start_date', 'end_date', 'days_count', 'status', 'reason',
        'approved_by', 'approved_at', 'review_note', 'cancelled_at',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
