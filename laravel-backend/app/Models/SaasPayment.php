<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasPayment extends Model
{
    /** Recorded by hand at the platform: cash or bank transfer. Mobile money and card are off for now. */
    public const METHODS = ['CASH', 'BANK_TRANSFER'];

    protected $fillable = [
        'clinic_id',
        'saas_invoice_id',
        'flutterwave_transaction_id',
        'amount',
        'method',
        'reference',
        'paid_on',
        'status',
        'declared_by',
        'confirmed_by',
        'confirmed_at',
        'notes',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_on' => 'date:Y-m-d',
        'confirmed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SaasInvoice::class, 'saas_invoice_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
