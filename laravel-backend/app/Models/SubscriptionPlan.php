<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'monthly_price',
        'yearly_price',
        'currency',
        'max_users',
        'max_patients',
        'modules',
        'trial_days',
        'is_active',
    ];

    protected $casts = [
        'monthly_price' => 'float',
        'yearly_price' => 'float',
        'max_users' => 'integer',
        'max_patients' => 'integer',
        'modules' => 'array',
        'trial_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function priceFor(string $billingCycle): float
    {
        return $billingCycle === 'YEARLY' ? $this->yearly_price : $this->monthly_price;
    }

    public function includesModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }
}
