<?php

namespace App\Jobs;

use App\Services\Payments\SubscriptionCheckout;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Verifies a Flutterwave transaction announced by a webhook and applies it. Queued so the
 * webhook answers immediately; retried with backoff if the Flutterwave API is unreachable.
 * Unique per transaction id, and SubscriptionCheckout::process() is idempotent anyway.
 */
class ProcessFlutterwaveTransaction implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900, 3600];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $flwTransactionId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->flwTransactionId;
    }

    public function handle(SubscriptionCheckout $checkout): void
    {
        $checkout->process($this->flwTransactionId);
    }
}
