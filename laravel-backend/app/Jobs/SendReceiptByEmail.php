<?php

namespace App\Jobs;

use App\Mail\ReceiptMail;
use App\Models\Payment;
use App\Services\Receipts\ReceiptPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the receipt to the patient, outside the payment request: a network or mail error is retried
 * by the queue and never blocks the cashier. A patient without an e-mail address is skipped.
 */
class SendReceiptByEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Seconds to wait before each retry. */
    public array $backoff = [30, 300, 1800];

    public function __construct(public int $paymentId)
    {
    }

    public function handle(): void
    {
        $payment = Payment::query()->withoutGlobalScopes()->with(['invoice.clinic', 'invoice.patient'])->findOrFail($this->paymentId);
        $email = $payment->invoice->patient?->email;
        if (!$email) {
            Log::info('Quittance non envoyée : le patient n’a pas d’adresse e-mail.', ['payment_id' => $payment->id]);

            return;
        }

        Mail::to($email)->send(new ReceiptMail($payment, ReceiptPdf::render($payment)));
        $payment->forceFill(['receipt_sent_at' => now()])->save();
    }
}
