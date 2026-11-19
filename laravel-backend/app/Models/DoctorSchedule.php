<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;

/** One doctor, one day: present, on call (garde) or unavailable. */
class DoctorSchedule extends Model
{
    use BelongsToClinic;

    public const STATUSES = ['PRESENT', 'ON_CALL', 'UNAVAILABLE'];
    /** Statuses that allow an appointment with the doctor that day. */
    public const BOOKABLE = ['PRESENT', 'ON_CALL'];

    protected $fillable = ['clinic_id', 'doctor_id', 'work_date', 'status', 'note', 'recorded_by'];

    protected $casts = ['work_date' => 'date:Y-m-d'];

    public function doctor() { return $this->belongsTo(User::class, 'doctor_id'); }
}
