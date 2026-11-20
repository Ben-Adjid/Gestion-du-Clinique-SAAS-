<?php

namespace App\Models;
use App\Models\Concerns\BelongsToClinic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use BelongsToClinic;
    use SoftDeletes;

    protected $fillable = [
        'insurance_rate',
        'clinic_id',
        'patient_id',
        'invoice_number',
        'subtotal',
        'tax_amount',
        'tax_rate',
        'total_amount',
        'status',
        'issued_at',
        'paid_at',
        'due_date',
        'created_by',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_by',
    ];

    public const STATUS_LABELS = [
        'DRAFT' => 'Brouillon',
        'ISSUED' => 'À payer',
        'PAID' => 'Payée',
        'OVERDUE' => 'En retard',
        'CANCELLED' => 'Annulée',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'due_date' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
