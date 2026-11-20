<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToClinic;

    /** Label printed on the receipt, for every method that exists in the history. */
    public const METHOD_LABELS = [
        'CASH' => 'Espèces',
        'CHEQUE' => 'Chèque',
        'WAVE' => 'Wave',
        'ORANGE_MONEY' => 'Orange Money',
        'MOBILE_MONEY' => 'Mobile Money',
        'BANK_TRANSFER' => 'Virement',
        'CARD' => 'Carte bancaire',
        'STRIPE' => 'Carte bancaire',
        'INSURANCE' => 'Prise en charge assurance',
    ];

    /**
     * Methods a clinic accepts for the bills of its patients: cash or cheque (both registered at the cashier),
     * and the insurer's share, which is not money received from the patient. Mobile money and card payments
     * are not taken for hospital bills.
     */
    public const CLINIC_METHODS = ['CASH', 'CHEQUE', 'INSURANCE'];

    /** Methods that need a transaction reference (insurance file). */
    public const METHODS_WITH_REFERENCE = ['INSURANCE'];

    /** A cheque stays PENDING until it is cashed or rejected by the bank; both final states are locked. */
    public const CHEQUE_STATUSES = ['PENDING', 'CASHED', 'REJECTED'];

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
        'cheque_number',
        'cheque_bank',
        'cheque_drawer',
        'cheque_date',
        'cheque_status',
        'cheque_status_changed_at',
        'cheque_status_changed_by',
        'cheque_status_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_datetime' => 'datetime',
            'receipt_sent_at' => 'datetime',
            'cheque_date' => 'date:Y-m-d',
            'cheque_status_changed_at' => 'datetime',
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

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cheque_status_changed_by');
    }

    /** Verification code printed on the receipt and shown with it (same value on screen and on the PDF). */
    public function getVerificationCodeAttribute(): string
    {
        return \App\Services\Documents\VerificationCode::for('RECEIPT', (string) $this->id, (string) $this->receipt_number, number_format((float) $this->amount, 2, '.', ''));
    }
}
