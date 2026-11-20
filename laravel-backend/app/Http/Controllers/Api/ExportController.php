<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Accounting and insurance exports (Enterprise plan): an OHADA-style journal and an insurance bordereau.
 * Both return semicolon-separated CSV with a UTF-8 BOM so spreadsheets open them as written.
 */
class ExportController extends Controller
{
    /** Chart of accounts used by the journal (OHADA plan comptable, classe 4 and 7). */
    public const ACCOUNT_CLIENTS = '411';
    public const ACCOUNT_SALES = '706';
    public const ACCOUNT_CASH = '571';
    public const ACCOUNT_INSURERS = '4117';

    public function ohadaJournal(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $rows = [['Date', 'Journal', 'Piece', 'Compte', 'Libelle', 'Debit (FCFA)', 'Credit (FCFA)']];

        Invoice::query()->where('status', '!=', 'CANCELLED')
            ->whereBetween('created_at', [$from, $to])->orderBy('created_at')->get()
            ->each(function (Invoice $invoice) use (&$rows) {
                $date = $invoice->created_at->format('Y-m-d');
                $label = 'Facture '.$invoice->invoice_number;
                $rows[] = [$date, 'VE', $invoice->invoice_number, self::ACCOUNT_CLIENTS, $label, $this->amount($invoice->total_amount), ''];
                $rows[] = [$date, 'VE', $invoice->invoice_number, self::ACCOUNT_SALES, $label, '', $this->amount($invoice->total_amount)];
            });

        Payment::query()->where('status', 'CONFIRMED')
            ->whereBetween('payment_datetime', [$from, $to])->orderBy('payment_datetime')->get()
            ->each(function (Payment $payment) use (&$rows) {
                $date = $payment->payment_datetime->format('Y-m-d');
                $label = 'Reglement '.$payment->receipt_number;
                $debitAccount = $payment->payment_method === 'INSURANCE' ? self::ACCOUNT_INSURERS : self::ACCOUNT_CASH;
                $rows[] = [$date, 'CA', $payment->receipt_number, $debitAccount, $label, $this->amount($payment->amount), ''];
                $rows[] = [$date, 'CA', $payment->receipt_number, self::ACCOUNT_CLIENTS, $label, '', $this->amount($payment->amount)];
            });

        return $this->csv($rows, 'journal-ohada-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv');
    }

    public function insuranceBordereau(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $rows = [['Date', 'Recu', 'Facture', 'Assureur', 'Reference', 'Patient', 'N HARIRI', 'Montant pris en charge (FCFA)']];

        Payment::query()->where('status', 'CONFIRMED')->where('payment_method', 'INSURANCE')
            ->whereBetween('payment_datetime', [$from, $to])->with('invoice.patient')->orderBy('payment_datetime')->get()
            ->each(function (Payment $payment) use (&$rows) {
                $patient = $payment->invoice?->patient;
                $rows[] = [
                    $payment->payment_datetime->format('Y-m-d'),
                    $payment->receipt_number,
                    $payment->invoice?->invoice_number,
                    $payment->insurer_name,
                    $payment->payment_reference,
                    $patient ? trim($patient->last_name.' '.$patient->first_name) : '',
                    $patient?->patient_unique_id,
                    $this->amount($payment->amount),
                ];
            });

        $rows[] = ['', '', '', '', '', '', 'TOTAL', $this->amount(Payment::query()->where('status', 'CONFIRMED')->where('payment_method', 'INSURANCE')
            ->whereBetween('payment_datetime', [$from, $to])->sum('amount'))];

        return $this->csv($rows, 'bordereau-assurance-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv');
    }

    /** @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], ['from.required' => 'Indiquez le début de la période.', 'to.after_or_equal' => 'La fin doit être après le début.']);

        return [\Illuminate\Support\Carbon::parse($validated['from'])->startOfDay(), \Illuminate\Support\Carbon::parse($validated['to'])->endOfDay()];
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }

    private function csv(array $rows, string $filename): Response
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
