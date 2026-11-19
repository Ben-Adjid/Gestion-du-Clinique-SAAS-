<?php

namespace App\Services\Receipts;

use App\Models\Clinic;
use App\Models\Payment;

/**
 * Receipt numbers REC-MED-<year>-<0001…>, sequential per clinic and per year, never reused.
 * Must run inside the transaction that records the payment: the clinic row is locked so two
 * cashiers cannot take the same number.
 */
final class ReceiptNumbering
{
    public static function next(int $clinicId, ?int $year = null): string
    {
        $year ??= now()->year;
        Clinic::query()->whereKey($clinicId)->lockForUpdate()->firstOrFail();

        $prefix = "REC-MED-{$year}-";
        $last = Payment::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->pluck('receipt_number')
            ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Sum of the payments confirmed up to and including this one (for "amount paid to date"). */
    public static function paidToDate(Payment $payment): float
    {
        return round((float) Payment::query()->withoutGlobalScopes()
            ->where('invoice_id', $payment->invoice_id)
            ->where('status', 'CONFIRMED')
            ->where('id', '<=', $payment->id)
            ->sum('amount'), 2);
    }
}
