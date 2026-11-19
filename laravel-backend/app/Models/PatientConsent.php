<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** Optional consents of a patient. Care and legal retention do not depend on them. */
class PatientConsent extends Model
{
    use BelongsToClinic;

    public const TYPES = [
        'SMS_REMINDERS' => 'Rappels de rendez-vous par SMS',
        'EMAIL_COMMUNICATIONS' => 'Messages et informations par e-mail',
        'STATISTICS' => 'Utilisation anonymisée de mes données pour les statistiques de santé publique',
    ];

    protected $fillable = ['clinic_id', 'patient_id', 'type', 'granted', 'recorded_by', 'changed_at'];

    protected $casts = [
        'granted' => 'boolean',
        'changed_at' => 'datetime',
    ];

    public function patient() { return $this->belongsTo(Patient::class); }
}
