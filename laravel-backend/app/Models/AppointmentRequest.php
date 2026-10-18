<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;

class AppointmentRequest extends Model
{
    use BelongsToClinic;
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'doctor_id',
        'appointment_id',
        'requested_datetime',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'requested_datetime' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function clinic() { return $this->belongsTo(Clinic::class); }
    public function patient() { return $this->belongsTo(Patient::class); }
    public function doctor() { return $this->belongsTo(User::class, 'doctor_id'); }
    public function appointment() { return $this->belongsTo(Appointment::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
