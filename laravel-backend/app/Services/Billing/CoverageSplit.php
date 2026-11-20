<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;

/**
 * Split of an invoice between the insurer and the patient (ticket modérateur).
 * The server computes it; the browser only displays it.
 *
 *   part_assurance   = total * taux / 100
 *   ticket_patient   = total - part_assurance
 *   paid_insurer     = confirmed INSURANCE payments
 *   paid_patient     = other confirmed payments (acomptes du patient)
 *   reste_patient    = max(0, ticket_patient - paid_patient)     (only when a rate is set)
 *   reste_global     = max(0, total - paid)                       (solde de la facture)
 *
 * A null rate means no split: the patient part is the whole invoice.
 */
final class CoverageSplit
{
    public const DEFAULT_RATE = 80.0;

    /** @return array{total: float, rate: ?float, insurer_share: float, patient_share: float, paid: float, paid_insurer: float, paid_patient: float, remaining: float, patient_remaining: float} */
    public static function for(Invoice $invoice): array
    {
        $total = round((float) $invoice->total_amount, 2);
        $rate = $invoice->insurance_rate === null ? null : round((float) $invoice->insurance_rate, 2);
        $insurerShare = $rate === null ? 0.0 : round($total * $rate / 100, 2);
        $patientShare = round($total - $insurerShare, 2);

        $confirmed = Payment::query()->withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)->where('status', 'CONFIRMED');
        $paidInsurer = round((float) (clone $confirmed)->where('payment_method', 'INSURANCE')->sum('amount'), 2);
        $paidPatient = round((float) (clone $confirmed)->where('payment_method', '!=', 'INSURANCE')->sum('amount'), 2);
        $paid = round($paidInsurer + $paidPatient, 2);

        return [
            'total' => $total,
            'rate' => $rate,
            'insurer_share' => $insurerShare,
            'patient_share' => $patientShare,
            'paid' => $paid,
            'paid_insurer' => $paidInsurer,
            'paid_patient' => $paidPatient,
            'remaining' => max(0.0, round($total - $paid, 2)),
            'patient_remaining' => $rate === null
                ? max(0.0, round($total - $paid, 2))
                : max(0.0, round($patientShare - $paidPatient, 2)),
        ];
    }
}
