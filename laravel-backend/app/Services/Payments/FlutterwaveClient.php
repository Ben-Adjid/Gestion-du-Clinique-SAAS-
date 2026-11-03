<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Thin client for the Flutterwave v3 REST API (Standard checkout + transaction verification). */
class FlutterwaveClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.flutterwave.secret_key')) && filled(config('services.flutterwave.secret_hash'));
    }

    /**
     * Creates a hosted checkout and returns the payment link.
     *
     * @param array<string, mixed> $payload
     */
    public function createPayment(array $payload): string
    {
        $response = $this->request()->post('/payments', $payload);
        $link = $response->json('data.link');
        if (!$response->successful() || $response->json('status') !== 'success' || !is_string($link)) {
            throw new RuntimeException('Flutterwave a refusé la création du paiement : '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return $link;
    }

    /**
     * Server-to-server verification of a transaction, the only trusted source of truth.
     *
     * @return array<string, mixed>|null the transaction data, or null when Flutterwave does not know it
     */
    public function verify(int $transactionId): ?array
    {
        $response = $this->request()->get("/transactions/{$transactionId}/verify");
        if ($response->status() === 404 || $response->status() === 400) {
            return null;
        }
        if (!$response->successful() || $response->json('status') !== 'success') {
            throw new RuntimeException('Vérification Flutterwave impossible : '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return $response->json('data');
    }

    /** Constant-time comparison of the webhook "verif-hash" header. */
    public function validSignature(?string $header): bool
    {
        $secret = (string) config('services.flutterwave.secret_hash');

        return $secret !== '' && is_string($header) && hash_equals($secret, $header);
    }

    private function request(): PendingRequest
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Le paiement en ligne Flutterwave n’est pas configuré sur ce serveur.');
        }

        return Http::baseUrl(rtrim((string) config('services.flutterwave.base_url'), '/'))
            ->withToken((string) config('services.flutterwave.secret_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.flutterwave.timeout', 20))
            ->retry(2, 500, fn ($exception) => $exception instanceof ConnectionException, throw: false);
    }
}
