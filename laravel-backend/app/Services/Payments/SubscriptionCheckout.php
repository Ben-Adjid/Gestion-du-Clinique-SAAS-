<?php

namespace App\Services\Payments;

use App\Models\Clinic;
use App\Models\FlutterwaveTransaction;
use App\Models\SaasInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Online payment of a clinic subscription through Flutterwave.
 *
 * The amount, currency and purpose are always computed server-side and stored before the
 * customer is redirected. When Flutterwave reports a payment (webhook or redirect), the
 * transaction is re-verified with the Flutterwave API and compared with what was stored:
 * nothing sent by the browser or by the webhook body is trusted.
 */
class SubscriptionCheckout
{
    public function __construct(
        private readonly FlutterwaveClient $flutterwave,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function forInvoice(Clinic $clinic, User $user, SaasInvoice $invoice, string $redirectUrl): FlutterwaveTransaction
    {
        if ((int) $invoice->clinic_id !== (int) $clinic->id) {
            throw ValidationException::withMessages(['saas_invoice_id' => 'Facture introuvable.']);
        }
        if (!in_array($invoice->status, ['PENDING', 'OVERDUE'], true) || $invoice->balance <= 0) {
            throw ValidationException::withMessages(['saas_invoice_id' => 'Cette facture est déjà réglée ou annulée.']);
        }

        return $this->start($clinic, $user, [
            'purpose' => 'INVOICE',
            'saas_invoice_id' => $invoice->id,
            'amount' => round($invoice->balance, 2),
            'currency' => $invoice->currency,
            'description' => "Facture {$invoice->number}",
        ], $redirectUrl);
    }

    public function forRenewal(Clinic $clinic, User $user, SubscriptionPlan $plan, string $billingCycle, string $redirectUrl): FlutterwaveTransaction
    {
        $subscription = $clinic->subscription()->firstOrFail();
        if ($subscription->invoices()->whereIn('status', ['PENDING', 'OVERDUE'])->exists()) {
            throw ValidationException::withMessages(['plan_code' => 'Réglez d’abord la facture d’abonnement en cours.']);
        }
        if (!$plan->is_active) {
            throw ValidationException::withMessages(['plan_code' => 'Cette formule n’est plus proposée.']);
        }
        $this->subscriptions->assertPlanFits($clinic, $plan);

        return $this->start($clinic, $user, [
            'purpose' => 'RENEWAL',
            'plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'amount' => $plan->priceFor($billingCycle),
            'currency' => $plan->currency,
            'description' => "Formule {$plan->name}, ".($billingCycle === 'YEARLY' ? '12 mois' : '1 mois'),
        ], $redirectUrl);
    }

    /**
     * Verifies a transaction with Flutterwave and applies it exactly once.
     * Returns the local transaction, or null when it does not belong to this platform.
     */
    public function process(int $flwTransactionId): ?FlutterwaveTransaction
    {
        $data = $this->flutterwave->verify($flwTransactionId);
        if ($data === null || !isset($data['tx_ref'])) {
            Log::warning('Flutterwave: transaction inconnue lors de la vérification.', ['id' => $flwTransactionId]);

            return null;
        }

        return DB::transaction(function () use ($data, $flwTransactionId) {
            $transaction = FlutterwaveTransaction::query()->withoutGlobalScopes()
                ->where('tx_ref', (string) $data['tx_ref'])
                ->lockForUpdate()
                ->first();
            if ($transaction === null) {
                Log::warning('Flutterwave: tx_ref étranger à la plateforme.', ['tx_ref' => $data['tx_ref']]);

                return null;
            }
            // Idempotence: a transaction is applied once, whatever the number of notifications.
            if ($transaction->status === 'SUCCESSFUL') {
                return $transaction;
            }
            $alreadyUsed = FlutterwaveTransaction::query()->withoutGlobalScopes()
                ->where('flw_transaction_id', $flwTransactionId)
                ->whereKeyNot($transaction->id)
                ->exists();
            if ($alreadyUsed) {
                Log::warning('Flutterwave: transaction déjà rattachée à un autre paiement.', ['id' => $flwTransactionId]);

                return $transaction;
            }

            $base = [
                'flw_transaction_id' => $flwTransactionId,
                'flw_ref' => $data['flw_ref'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'charged_amount' => $data['charged_amount'] ?? $data['amount'] ?? null,
                'verification' => array_intersect_key($data, array_flip(['id', 'tx_ref', 'flw_ref', 'amount', 'charged_amount', 'currency', 'status', 'payment_type', 'created_at', 'processor_response'])),
                'verified_at' => now(),
            ];

            $failure = match (true) {
                ($data['status'] ?? null) !== 'successful' => 'Paiement non abouti ('.($data['processor_response'] ?? $data['status'] ?? 'inconnu').').',
                strtoupper((string) ($data['currency'] ?? '')) !== strtoupper($transaction->currency) => 'Devise reçue différente de la devise attendue.',
                (float) ($data['amount'] ?? 0) + 0.001 < $transaction->amount => 'Montant reçu inférieur au montant attendu.',
                default => null,
            };
            if ($failure !== null) {
                if ($transaction->status === 'PENDING') {
                    $transaction->update([...$base, 'status' => 'FAILED', 'failure_reason' => $failure]);
                    $this->subscriptions->onlinePaymentFailed($transaction);
                }
                if (($data['status'] ?? null) === 'successful') {
                    Log::alert('Flutterwave: paiement réussi mais incohérent, à examiner.', ['tx_ref' => $transaction->tx_ref, 'raison' => $failure]);
                }

                return $transaction->fresh();
            }

            $transaction->update([...$base, 'status' => 'SUCCESSFUL', 'failure_reason' => null]);
            $this->subscriptions->applyOnlinePayment($transaction->fresh());

            return $transaction->fresh();
        });
    }

    /** @param array{purpose: string, amount: float, currency: string, description: string, saas_invoice_id?: int, plan_id?: int, billing_cycle?: string} $details */
    private function start(Clinic $clinic, User $user, array $details, string $redirectUrl): FlutterwaveTransaction
    {
        if (!$this->flutterwave->isConfigured()) {
            throw ValidationException::withMessages(['payment' => 'Le paiement en ligne n’est pas encore activé. Utilisez la déclaration de paiement Mobile Money.']);
        }
        if ($details['amount'] <= 0) {
            throw ValidationException::withMessages(['payment' => 'Montant à payer invalide.']);
        }
        $subscription = $clinic->subscription()->firstOrFail();

        // Older unfinished attempts for the same purchase are superseded by this one.
        FlutterwaveTransaction::query()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'PENDING')
            ->where('purpose', $details['purpose'])
            ->when($details['purpose'] === 'INVOICE', fn ($query) => $query->where('saas_invoice_id', $details['saas_invoice_id']))
            ->update(['status' => 'CANCELLED', 'failure_reason' => 'Remplacé par une nouvelle tentative.']);

        $transaction = FlutterwaveTransaction::create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'purpose' => $details['purpose'],
            'saas_invoice_id' => $details['saas_invoice_id'] ?? null,
            'plan_id' => $details['plan_id'] ?? null,
            'billing_cycle' => $details['billing_cycle'] ?? null,
            'tx_ref' => 'CLQ-'.$clinic->id.'-'.Str::upper((string) Str::ulid()),
            'amount' => $details['amount'],
            'currency' => strtoupper($details['currency']),
            'status' => 'PENDING',
        ]);

        try {
            $link = $this->createLink($transaction, $clinic, $user, $details['description'], $redirectUrl);
        } catch (\Throwable $exception) {
            $transaction->update(['status' => 'FAILED', 'failure_reason' => mb_substr($exception->getMessage(), 0, 255)]);
            Log::error('Flutterwave: création du paiement impossible.', ['tx_ref' => $transaction->tx_ref, 'erreur' => $exception->getMessage()]);
            throw ValidationException::withMessages(['payment' => 'Le service de paiement est momentanément indisponible. Réessayez dans quelques minutes.']);
        }
        $transaction->update(['checkout_link' => $link]);

        return $transaction;
    }

    private function createLink(FlutterwaveTransaction $transaction, Clinic $clinic, User $user, string $description, string $redirectUrl): string
    {
        return $this->flutterwave->createPayment([
            'tx_ref' => $transaction->tx_ref,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'redirect_url' => $redirectUrl,
            'payment_options' => config('services.flutterwave.payment_options'),
            'customer' => array_filter([
                'email' => $user->email,
                'name' => $user->name,
                'phonenumber' => $user->phone,
            ]),
            'meta' => [
                'organization_id' => $clinic->id,
                'clinic_id' => $clinic->id,
                'user_id' => $user->id,
                'purpose' => $transaction->purpose,
                'local_transaction_id' => $transaction->id,
            ],
            'customizations' => array_filter([
                'title' => config('app.name').' — abonnement',
                'description' => "{$clinic->name} : {$description}",
                'logo' => config('services.flutterwave.logo_url'),
            ]),
        ]);
    }
}
