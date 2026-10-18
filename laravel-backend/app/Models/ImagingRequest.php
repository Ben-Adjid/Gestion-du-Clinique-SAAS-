<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImagingRequest extends Model
{
    use BelongsToClinic;
    protected $fillable = ['clinic_id', 'patient_id', 'doctor_id', 'exam_id', 'consultation_id', 'requested_at', 'status', 'priority', 'clinical_info'];

    public const STATUS_LABELS = [
        'PENDING' => 'À réaliser',
        'IN_PROGRESS' => 'Examen réalisé',
        'READY' => 'Compte rendu rédigé',
        'SIGNED' => 'Compte rendu signé',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(ImagingFile::class, 'request_id');
    }

    protected function casts(): array
    {
        return ['requested_at' => 'datetime'];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(ImagingExam::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ImagingReport::class, 'request_id');
    }
}
