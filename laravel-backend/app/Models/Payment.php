<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToClinic;
    protected $fillable = [
        'clinic_id',
        'invoice_id',
        'amount',
        'payment_method',
        'payment_reference',
        'payment_datetime',
        'received_by',
        'status',
        'cash_closing_id',
    ];

    public const METHOD_LABELS = [
        'CASH' => 'Espèces',
        'MOBILE_MONEY' => 'Mobile Money',
        'BANK_TRANSFER' => 'Virement',
        'STRIPE' => 'Carte bancaire',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_datetime' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
