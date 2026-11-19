<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalCertificate extends Model
{
    use BelongsToClinic;

    public const TYPES = [
        'WORK_STOPPAGE' => 'Certificat d’arrêt de travail',
        'SCHOOL_ABSENCE' => 'Certificat d’absence scolaire',
        'FITNESS' => 'Certificat d’aptitude',
        'GENERAL' => 'Certificat médical',
    ];

    protected $fillable = [
        'clinic_id', 'patient_id', 'doctor_id', 'consultation_id', 'number', 'type',
        'start_date', 'end_date', 'content', 'signature_hash', 'issued_at',
        'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'content' => 'encrypted',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected $appends = ['type_label', 'days'];

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function doctor(): BelongsTo { return $this->belongsTo(User::class, 'doctor_id'); }
    public function consultation(): BelongsTo { return $this->belongsTo(Consultation::class); }
    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getDaysAttribute(): ?int
    {
        return $this->start_date && $this->end_date ? (int) $this->start_date->diffInDays($this->end_date) + 1 : null;
    }
}
