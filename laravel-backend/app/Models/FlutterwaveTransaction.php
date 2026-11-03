<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlutterwaveTransaction extends Model
{
    use BelongsToClinic;

    protected $fillable = [
        'clinic_id', 'user_id', 'subscription_id', 'purpose', 'saas_invoice_id', 'plan_id', 'billing_cycle', 'tx_ref',
        'amount', 'currency', 'status', 'checkout_link', 'flw_transaction_id', 'flw_ref', 'payment_type',
        'charged_amount', 'failure_reason', 'verification', 'verified_at',
    ];

    protected $hidden = ['verification'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'charged_amount' => 'float',
            'verification' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo { return $this->belongsTo(Clinic::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(ClinicSubscription::class, 'subscription_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(SaasInvoice::class, 'saas_invoice_id'); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
