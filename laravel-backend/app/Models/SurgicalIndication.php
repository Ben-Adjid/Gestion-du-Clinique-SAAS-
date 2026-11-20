<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surgical indication of a patient: requested by a doctor, validated by the head of the specialty,
 * completed by the pre-anesthesia assessment. An operation is scheduled only from an approved one.
 */
class SurgicalIndication extends Model
{
    use BelongsToClinic;

    public const URGENCIES = ['ELECTIVE', 'URGENT', 'EMERGENCY'];

    protected $fillable = [
        'clinic_id', 'patient_id', 'consultation_id', 'requested_by', 'specialty_id', 'operation_type', 'urgency', 'motif',
        'status', 'head_decided_by', 'head_decided_at', 'refusal_reason', 'asa', 'contraindications', 'allergies_reviewed',
        'pre_anesthesia_by', 'pre_anesthesia_at',
    ];

    protected $casts = [
        'head_decided_at' => 'datetime',
        'pre_anesthesia_at' => 'datetime',
        'allergies_reviewed' => 'boolean',
        'motif' => 'encrypted',
        'contraindications' => 'encrypted',
    ];

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function specialty(): BelongsTo { return $this->belongsTo(Specialty::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }

    /** Ready to schedule: approved by the head of the specialty, and assessed by the anesthesia. */
    public function readyToSchedule(): bool
    {
        return $this->status === 'APPROVED' && $this->asa !== null && $this->allergies_reviewed && $this->pre_anesthesia_at !== null;
    }
}
