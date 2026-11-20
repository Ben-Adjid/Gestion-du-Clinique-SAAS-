<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** A hospital bed in a ward, free, occupied by a patient, or out of service. */
class Bed extends Model
{
    use BelongsToClinic;

    /** AVAILABLE (free), OCCUPIED (patient), CLEANING (being disinfected after discharge), MAINTENANCE (out of service). */
    public const STATUSES = ['AVAILABLE', 'OCCUPIED', 'CLEANING', 'MAINTENANCE'];

    protected $fillable = ['clinic_id', 'room_id', 'code', 'ward', 'status', 'patient_id', 'admitted_at', 'note', 'updated_by'];

    protected $casts = ['admitted_at' => 'datetime'];

    public function patient() { return $this->belongsTo(Patient::class); }
    public function room() { return $this->belongsTo(Room::class); }
}
