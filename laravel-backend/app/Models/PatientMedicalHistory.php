<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientMedicalHistory extends Model
{
    protected $table = 'patient_medical_history';

    protected $fillable = ['condition', 'start_date', 'end_date', 'notes'];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];
}
