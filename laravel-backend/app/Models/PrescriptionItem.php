<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionItem extends Model
{
    protected $fillable = [
        'prescription_id',
        'medication_id',
        'dosage_prescribed',
        'frequency',
        'duration_days',
        'quantity',
        'dispensed_quantity',
        'special_instructions',
    ];

    protected $appends = ['remaining_quantity'];

    public function getRemainingQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->dispensed_quantity);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }
}
