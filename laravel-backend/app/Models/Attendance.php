<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToClinic;

    protected $table = 'attendance';

    protected $fillable = [
        'clinic_id', 'employee_id', 'work_date', 'check_in_datetime', 'check_out_datetime', 'status',
        'check_in_method', 'location_gps', 'notes', 'recorded_by',
    ];

    // work_date is stored as a plain "Y-m-d" string (no cast) so that range filters and
    // the (employee_id, work_date) unique key compare exact days.
    protected $casts = [
        'check_in_datetime' => 'datetime',
        'check_out_datetime' => 'datetime',
    ];

    protected $appends = ['worked_hours'];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }

    public function getWorkedHoursAttribute(): ?float
    {
        return $this->check_in_datetime && $this->check_out_datetime
            ? round($this->check_in_datetime->diffInMinutes($this->check_out_datetime) / 60, 2)
            : null;
    }
}
