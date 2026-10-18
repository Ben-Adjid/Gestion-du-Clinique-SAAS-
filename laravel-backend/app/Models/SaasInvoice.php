<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaasInvoice extends Model
{
    protected $fillable = [
        'clinic_id',
        'subscription_id',
        'number',
        'plan_code',
        'billing_cycle',
        'period_start',
        'period_end',
        'amount',
        'currency',
        'status',
        'due_date',
        'issued_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected $appends = ['amount_paid', 'balance'];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClinicSubscription::class, 'subscription_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaasPayment::class);
    }

    public function getAmountPaidAttribute(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->where('status', 'CONFIRMED')->sum('amount');
        }

        return (float) $this->payments()->where('status', 'CONFIRMED')->sum('amount');
    }

    public function getBalanceAttribute(): float
    {
        return max(0, round($this->amount - $this->amount_paid, 2));
    }
}
