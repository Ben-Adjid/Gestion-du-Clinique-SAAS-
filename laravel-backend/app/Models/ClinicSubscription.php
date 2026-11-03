<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicSubscription extends Model
{
    protected $fillable = [
        'clinic_id',
        'plan_id',
        'billing_cycle',
        'status',
        'price',
        'currency',
        'flw_transaction_id',
        'flw_ref',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'grace_days',
        'auto_renew',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'price' => 'float',
        'current_period_start' => 'date:Y-m-d',
        'current_period_end' => 'date:Y-m-d',
        'trial_ends_at' => 'date:Y-m-d',
        'grace_days' => 'integer',
        'auto_renew' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SaasInvoice::class, 'subscription_id');
    }

    public function monthlyRecurringRevenue(): float
    {
        return $this->billing_cycle === 'YEARLY' ? round($this->price / 12, 2) : $this->price;
    }
}
