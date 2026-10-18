<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientContact extends Model
{
    protected $table = 'patient_contacts';

    protected $fillable = ['emergency_contact_name', 'emergency_contact_phone', 'relationship'];
}
