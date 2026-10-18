<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabRequest extends Model
{
    use BelongsToClinic;
    protected $fillable = [
        'clinic_id', 'patient_id', 'doctor_id', 'consultation_id', 'requested_at', 'status',
        'priority', 'clinical_info', 'sample_number',
    ];

    /** Workflow: requested → sample collected → analysis → results entered → validated. */
    public const STATUS_LABELS = [
        'PENDING' => 'En attente de prélèvement',
        'RECEIVED' => 'Prélevé',
        'IN_PROGRESS' => 'En analyse',
        'READY' => 'Résultats à valider',
        'SIGNED' => 'Validé',
    ];

    public function samples(): HasMany
    {
        return $this->hasMany(LabSample::class, 'request_id');
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

    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(LabExam::class, 'lab_request_exams', 'lab_request_id', 'lab_exam_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class, 'request_id');
    }
}
