<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A staff member's justification for a day without clocking in. */
class AbsenceRequest extends Model
{
    use BelongsToClinic;

    protected $fillable = [
        'clinic_id', 'employee_id', 'work_date', 'reason', 'attachment_path', 'status',
        'review_note', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'work_date' => 'date:Y-m-d',
        'reviewed_at' => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
