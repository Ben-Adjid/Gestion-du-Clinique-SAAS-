<?php

namespace App\Services\Receipts;

use App\Models\Payment;
use App\Services\Documents\OfficialPdf;

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

        return ['total' => $total, 'paidToDate' => $paidToDate, 'remaining' => $remaining, 'settled' => $remaining <= 0.0,
            'coverage' => \App\Services\Billing\CoverageSplit::for($payment->invoice)];
    }

    public static function render(Payment $payment): string
    {
        $payment->loadMissing(['invoice.clinic', 'invoice.patient', 'invoice.items', 'invoice.payments', 'invoice.creator.doctorProfile']);
        $invoice = $payment->invoice;
        $clinic = $invoice->clinic;
        $patient = $invoice->patient;
        ['total' => $total, 'paidToDate' => $paidToDate, 'remaining' => $remaining, 'settled' => $settled] = self::figures($payment);

        $doctor = $invoice->creator;
        $doctorName = trim((string) $doctor?->name);
        // "Dr Fatimé ..." already carries the title: never print "Dr Dr".
        $doctorLabel = $doctorName === '' ? null : (preg_match('/^Dr\b/u', $doctorName) ? $doctorName : 'Dr '.$doctorName);

        return OfficialPdf::render('pdf.receipt', [
            'clinic' => $clinic,
            'logo' => \App\Services\ClinicLogo::pdfDataUri($clinic),
            'licence' => $clinic->settings['license_number'] ?? null,
            'doctorLabel' => $doctorLabel,
            'doctorLicence' => $doctor?->doctorProfile?->license_number,
            'verification' => $payment->verification_code,
            'patient' => $patient,
            'payment' => $payment,
            'method' => Payment::METHOD_LABELS[$payment->payment_method] ?? $payment->payment_method,
            'items' => $invoice->items,
            'total' => $total,
            'paidToDate' => $paidToDate,
            'remaining' => $remaining,
            'settled' => $settled,
            'coverage' => self::figures($payment)['coverage'],
            'invoiceNumber' => $invoice->invoice_number,
        ]);
    }

    /** "12 500 FCFA" with a space as thousands separator, as written in West Africa. */
    public static function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
