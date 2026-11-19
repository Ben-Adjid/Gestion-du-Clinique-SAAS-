<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** E-mail to the patient with the official receipt attached as a PDF. */
class ReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment, private readonly string $pdf)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre quittance '.$this->payment->receipt_number.' · '.($this->payment->invoice->clinic->name ?? 'Clinique+'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.receipt');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->payment->receipt_number.'.pdf')->withMime('application/pdf')];
    }
}
