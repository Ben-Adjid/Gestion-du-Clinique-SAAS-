<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyLot extends Model
{
    protected $fillable = ['inventory_id', 'lot_number', 'manufacturing_date', 'expiration_date', 'quantity', 'unit_cost', 'initial_quantity'];

    protected function casts(): array
    {
        return [
            'manufacturing_date' => 'date:Y-m-d',
            'expiration_date' => 'date:Y-m-d',
            'unit_cost' => 'float',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(PharmacyInventory::class, 'inventory_id');
    }

    public function isExpired(): bool
    {
        return $this->expiration_date->lt(today());
    }
}
