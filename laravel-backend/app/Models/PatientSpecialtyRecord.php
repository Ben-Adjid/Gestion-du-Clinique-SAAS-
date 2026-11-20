<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Measures of a patient recorded for his specialty (teeth, eyes, hearing, pregnancy...). */
class PatientSpecialtyRecord extends Model
{
    use BelongsToClinic;

    protected $fillable = ['clinic_id', 'patient_id', 'specialty_id', 'data', 'recorded_by', 'recorded_at'];

    protected $casts = [
        'recorded_at' => 'datetime',
        // Specialty measures are medical content: encrypted at rest.
        'data' => 'encrypted:array',
    ];

    public function specialty(): BelongsTo { return $this->belongsTo(Specialty::class); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
