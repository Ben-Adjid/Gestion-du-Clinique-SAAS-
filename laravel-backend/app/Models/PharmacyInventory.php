<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyInventory extends Model
{
    use BelongsToClinic;
    protected $table = 'pharmacy_inventory';

    protected $fillable = [
        'clinic_id',
        'medication_id',
        'quantity',
        'unit_cost',
        'selling_price',
        'min_threshold',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
        ];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function lots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PharmacyLot::class, 'inventory_id');
    }
}
