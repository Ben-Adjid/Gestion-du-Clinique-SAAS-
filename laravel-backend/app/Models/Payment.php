<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToClinic;

    /** Accepted payment methods, with the label printed on the receipt. */
    public const METHOD_LABELS = [
        'CASH' => 'Espèces',
        'WAVE' => 'Wave',
        'ORANGE_MONEY' => 'Orange Money',
        'MOBILE_MONEY' => 'Mobile Money',
        'BANK_TRANSFER' => 'Virement',
        'CARD' => 'Carte bancaire',
        'STRIPE' => 'Carte bancaire',
        'INSURANCE' => 'Prise en charge assurance',
    ];

    /** Methods that need a transaction reference (phone transfer, bank transfer, insurance file). */
    public const METHODS_WITH_REFERENCE = ['WAVE', 'ORANGE_MONEY', 'MOBILE_MONEY', 'BANK_TRANSFER', 'INSURANCE'];

    protected $fillable = [
        'clinic_id',
        'invoice_id',
        'amount',
        'payment_method',
        'payment_reference',
        'receipt_number',
        'insurer_name',
        'receipt_sent_at',
        'payment_datetime',
        'received_by',
        'status',
        'cash_closing_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_datetime' => 'datetime',
            'receipt_sent_at' => 'datetime',
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

    /** Verification code printed on the receipt and shown with it (same value on screen and on the PDF). */
    public function getVerificationCodeAttribute(): string
    {
        return \App\Services\Documents\VerificationCode::for('RECEIPT', (string) $this->id, (string) $this->receipt_number, number_format((float) $this->amount, 2, '.', ''));
    }
}
