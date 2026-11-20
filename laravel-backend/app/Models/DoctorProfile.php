<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** Specialty and employment status of a doctor: permanent staff or external consultant. */
class DoctorProfile extends Model
{
    use BelongsToClinic;

    public const EMPLOYMENTS = ['PERMANENT', 'CONSULTANT'];

    protected $fillable = ['clinic_id', 'user_id', 'specialty', 'specialty_id', 'employment', 'license_number', 'is_head'];

    protected $casts = ['is_head' => 'boolean'];

    public function specialtyRef(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Specialty::class, 'specialty_id'); }

    public function user() { return $this->belongsTo(User::class); }
}
