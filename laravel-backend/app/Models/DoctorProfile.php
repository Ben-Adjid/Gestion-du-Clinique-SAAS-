<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** Specialty and employment status of a doctor: permanent staff or external consultant. */
class DoctorProfile extends Model
{
    use BelongsToClinic;

    public const EMPLOYMENTS = ['PERMANENT', 'CONSULTANT'];

    protected $fillable = ['clinic_id', 'user_id', 'specialty', 'employment'];

    public function user() { return $this->belongsTo(User::class); }
}
