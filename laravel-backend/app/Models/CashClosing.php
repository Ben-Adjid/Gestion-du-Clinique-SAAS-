<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashClosing extends Model
{
    use BelongsToClinic;

    protected $fillable = [
        'clinic_id', 'cashier_id', 'business_date', 'payments_count', 'expected_cash', 'expected_mobile_money',
        'expected_other', 'counted_cash', 'difference', 'notes', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
            'expected_cash' => 'float',
            'expected_mobile_money' => 'float',
            'expected_other' => 'float',
            'counted_cash' => 'float',
            'difference' => 'float',
        ];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
