<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrugInteraction extends Model
{
    protected $fillable = ['drug_id_1', 'drug_id_2', 'severity', 'description'];

    public function firstDrug(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'drug_id_1');
    }

    public function secondDrug(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'drug_id_2');
    }
}
