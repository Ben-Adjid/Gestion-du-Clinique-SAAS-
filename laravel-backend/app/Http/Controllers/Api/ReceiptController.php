<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendReceiptByEmail;
use App\Models\Payment;
use App\Services\Receipts\ReceiptPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Official receipts of medical payments: PDF for preview and printing, and e-mail to the patient. */
class ReceiptController extends Controller
{
    public function show(Payment $payment): Response|JsonResponse
    {
        if ($payment->status !== 'CONFIRMED') {
            return response()->json(['message' => 'Seul un paiement confirmé donne droit à une quittance.'], 422);
        }

        return response(ReceiptPdf::render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$payment->receipt_number.'.pdf"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function sendByEmail(Payment $payment): JsonResponse
    {
        if ($payment->status !== 'CONFIRMED') {
            throw ValidationException::withMessages(['payment' => 'Seul un paiement confirmé peut être envoyé.']);
        }
        if (!$payment->invoice->patient?->email) {
            throw ValidationException::withMessages(['payment' => 'Ce patient n’a pas d’adresse e-mail dans son dossier.']);
        }

        SendReceiptByEmail::dispatch($payment->id);

        return response()->json(['message' => 'La quittance sera envoyée par e-mail dans quelques instants.'], 202);
    }
}
