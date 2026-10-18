<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consultation extends Model
{
    use BelongsToClinic;
    use SoftDeletes;

    protected $fillable = [
        'clinic_id', 'patient_id', 'doctor_id', 'appointment_id', 'consultation_datetime', 'status',
    ];

    protected $casts = ['consultation_datetime' => 'datetime'];

    /** Standard structure of a consultation note (section number => title). */
    public const SECTIONS = [
        1 => 'Motif de consultation',
        2 => 'Histoire de la maladie et antécédents utiles',
        3 => 'Examen clinique',
        4 => 'Hypothèses et conduite à tenir',
        5 => 'Examens complémentaires',
        6 => 'Traitement',
        7 => 'Conseils au patient',
        8 => 'Suivi et prochain rendez-vous',
    ];

    public function clinic() { return $this->belongsTo(Clinic::class); }
    public function appointment() { return $this->belongsTo(Appointment::class); }
    public function vitalSigns(): HasMany { return $this->hasMany(VitalSign::class); }
    public function certificates(): HasMany { return $this->hasMany(MedicalCertificate::class); }
    public function patient() { return $this->belongsTo(Patient::class); }
    public function doctor() { return $this->belongsTo(User::class, 'doctor_id'); }

    public function sections(): HasMany
    {
        return $this->hasMany(ConsultationSection::class)->orderBy('section_number');
    }

    public function diagnostics(): HasMany
    {
        return $this->hasMany(ConsultationDiagnostic::class, 'consultation_id', 'id');
    }

    public function digitalSignatures(): HasMany
    {
        return $this->hasMany(DigitalSignature::class);
    }
}
