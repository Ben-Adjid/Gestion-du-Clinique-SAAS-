<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VitalSign extends Model
{
    use BelongsToClinic;
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'consultation_id',
        'recorded_by',
        'systolic_bp',
        'diastolic_bp',
        'heart_rate',
        'respiratory_rate',
        'temperature',
        'spo2',
        'weight_kg',
        'height_cm',
        'notes',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'temperature' => 'float',
        'weight_kg' => 'float',
        'height_cm' => 'float',
    ];

    protected $appends = ['alerts'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return list<string> */
    public function getAlertsAttribute(): array
    {
        $alerts = [];
        if ($this->temperature !== null && $this->temperature >= 38.5) {
            $alerts[] = 'Fièvre';
        }
        if ($this->temperature !== null && $this->temperature < 35.0) {
            $alerts[] = 'Hypothermie';
        }
        if ($this->spo2 !== null && $this->spo2 < 92) {
            $alerts[] = 'Saturation basse';
        }
        if ($this->systolic_bp !== null && $this->systolic_bp >= 180) {
            $alerts[] = 'Tension très élevée';
        }
        if ($this->systolic_bp !== null && $this->systolic_bp < 90) {
            $alerts[] = 'Hypotension';
        }
        if ($this->heart_rate !== null && ($this->heart_rate > 120 || $this->heart_rate < 50)) {
            $alerts[] = 'Fréquence cardiaque anormale';
        }

        return $alerts;
    }
}
