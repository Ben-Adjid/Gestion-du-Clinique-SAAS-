<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientAllergy extends Model
{
    protected $table = 'patient_allergies';

    protected $fillable = ['allergen', 'severity', 'notes'];

    /** Clinical text is encrypted at rest (APP_KEY). */
    protected $casts = ['notes' => 'encrypted', 'allergen' => 'encrypted'];
}
