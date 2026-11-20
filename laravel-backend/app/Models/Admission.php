<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hospital stay: a patient in a bed, under a responsible doctor, from admission to discharge.
 * ADMIS and TRANSFERE are active stays; SORTI is closed by a discharge summary.
 */
class Admission extends Model
{
    use BelongsToClinic;

    public const ACTIVE = ['ADMIS', 'TRANSFERE'];

    protected $fillable = [
        'clinic_id', 'patient_id', 'bed_id', 'room_id', 'doctor_id', 'reason', 'status',
        'admitted_at', 'discharged_at', 'discharge_summary', 'created_by',
    ];

    protected $casts = [
        'admitted_at' => 'datetime',
        'discharged_at' => 'datetime',
        // Discharge summary is medical content: encrypted at rest.
        'discharge_summary' => 'encrypted',
    ];

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function bed(): BelongsTo { return $this->belongsTo(Bed::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function doctor(): BelongsTo { return $this->belongsTo(User::class, 'doctor_id'); }
    public function transfers(): HasMany { return $this->hasMany(AdmissionTransfer::class)->latest('transferred_at'); }

    public function isActive(): bool { return in_array($this->status, self::ACTIVE, true); }
}
