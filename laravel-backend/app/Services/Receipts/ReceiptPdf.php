<?php

namespace App\Services\Receipts;

use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Official receipt (quittance) of a medical payment, as a light A5 PDF.
 * The amounts are computed here, never trusted from the browser.
 */
final class ReceiptPdf
{
    /** The figures printed on the receipt: total, paid to date, balance and whether it is settled. */
    public static function figures(Payment $payment): array
    {
        $total = round((float) $payment->invoice->total_amount, 2);
        $paidToDate = ReceiptNumbering::paidToDate($payment);
        $remaining = max(0.0, round($total - $paidToDate, 2));

        return ['total' => $total, 'paidToDate' => $paidToDate, 'remaining' => $remaining, 'settled' => $remaining <= 0.0];
    }

    public static function render(Payment $payment): string
    {
        $payment->loadMissing(['invoice.clinic', 'invoice.patient', 'invoice.items', 'invoice.payments']);
        $invoice = $payment->invoice;
        $clinic = $invoice->clinic;
        $patient = $invoice->patient;
        ['total' => $total, 'paidToDate' => $paidToDate, 'remaining' => $remaining, 'settled' => $settled] = self::figures($payment);

        $pdf = Pdf::loadView('pdf.receipt', [
            'clinic' => $clinic,
            'licence' => $clinic->settings['license_number'] ?? null,
            'patient' => $patient,
            'payment' => $payment,
            'method' => Payment::METHOD_LABELS[$payment->payment_method] ?? $payment->payment_method,
            'items' => $invoice->items,
            'total' => $total,
            'paidToDate' => $paidToDate,
            'remaining' => $remaining,
            'settled' => $settled,
            'invoiceNumber' => $invoice->invoice_number,
        ])->setPaper('a5', 'portrait');

        return $pdf->output();
    }

    /** "12 500 FCFA" with a space as thousands separator, as written in West Africa. */
    public static function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
