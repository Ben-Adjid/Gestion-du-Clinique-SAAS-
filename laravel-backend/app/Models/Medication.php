<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medication extends Model
{
    use BelongsToClinic;
    use SoftDeletes;

    protected $fillable = ['clinic_id', 'name', 'dosage', 'form', 'manufacturer', 'price', 'description'];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
