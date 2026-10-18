<?php
namespace App\Models;
use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model {
    use BelongsToClinic;
    use SoftDeletes;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'doctor_id',
        'appointment_datetime',
        'status',
        'cancellation_reason',
        'reason',
        'is_walk_in',
        'checked_in_at',
        'called_at',
    ];

    protected $casts = [
        'appointment_datetime' => 'datetime',
        'is_walk_in' => 'boolean',
        'checked_in_at' => 'datetime',
        'called_at' => 'datetime',
    ];

    protected $appends = ['appointment_date', 'appointment_time'];
    
    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function doctor(): BelongsTo { return $this->belongsTo(User::class, 'doctor_id'); }

    public function getAppointmentDateAttribute(): ?string
    {
        return $this->appointment_datetime?->toDateString();
    }

    public function getAppointmentTimeAttribute(): ?string
    {
        return $this->appointment_datetime?->format('H:i');
    }
}
