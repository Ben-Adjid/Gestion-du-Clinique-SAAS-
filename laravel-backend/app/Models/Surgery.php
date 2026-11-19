<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** An operation planned in the operating theatre, with its operative and follow-up records. */
class Surgery extends Model
{
    use BelongsToClinic;

    public const STATUSES = ['PLANNED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];

    protected $fillable = [
        'clinic_id', 'patient_id', 'operation_type', 'scheduled_at', 'room', 'status',
        'surgeon_id', 'anesthetist_id', 'created_by', 'report', 'post_op_followup', 'report_signed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'report_signed_at' => 'datetime',
        // Medical content: encrypted at rest
        'report' => 'encrypted',
        'post_op_followup' => 'encrypted',
    ];

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function surgeon(): BelongsTo { return $this->belongsTo(User::class, 'surgeon_id'); }
    public function anesthetist(): BelongsTo { return $this->belongsTo(User::class, 'anesthetist_id'); }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'surgery_staff', 'surgery_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Only the assigned surgeon and anesthetist may write the operative report. */
    public function isReportAuthor(User $user): bool
    {
        return (int) $this->surgeon_id === (int) $user->id || (int) $this->anesthetist_id === (int) $user->id;
    }
}
