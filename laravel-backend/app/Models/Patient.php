<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use BelongsToClinic;
    use SoftDeletes;

    protected $fillable = [
        'clinic_id', 'user_id', 'patient_unique_id', 'first_name', 'last_name',
        'phone', 'email', 'date_of_birth', 'gender', 'photo_url', 'metadata',
        'national_id', 'marital_status', 'profession', 'address', 'city', 'blood_group', 'photo_path',
    ];

    protected $hidden = ['photo_path'];

    protected $casts = [
        'metadata' => 'json',
        'date_of_birth' => 'date:Y-m-d',
        'anonymized_at' => 'datetime',
    ];

    public function clinic() { return $this->belongsTo(Clinic::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function consultations() { return $this->hasMany(Consultation::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function emergencyContacts() { return $this->hasMany(PatientContact::class); }
    public function allergies() { return $this->hasMany(PatientAllergy::class); }
    public function medicalHistory() { return $this->hasMany(PatientMedicalHistory::class); }
    public function consents() { return $this->hasMany(PatientConsent::class); }
}
