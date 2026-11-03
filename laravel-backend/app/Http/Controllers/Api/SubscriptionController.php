<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubscriptionCheckoutRequest;
use App\Models\FlutterwaveTransaction;
use App\Models\SaasInvoice;
use App\Models\SubscriptionPlan;
use App\Services\Payments\SubscriptionCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Online payment of the clinic's own subscription (clinic administrator). */
class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionCheckout $checkout)
    {
    }

    public function plans()
    {
        return response()->json(['data' => SubscriptionPlan::query()->where('is_active', true)->orderBy('monthly_price')
            ->get(['id', 'code', 'name', 'description', 'monthly_price', 'yearly_price', 'currency', 'max_users', 'max_patients', 'modules', 'trial_days'])]);
    }

    /** Creates a Flutterwave hosted checkout and returns its URL. */
    public function checkout(SubscriptionCheckoutRequest $request)
    {
        $user = $request->user();
        $clinic = $user->clinic;
        $redirectUrl = rtrim((string) config('app.frontend_url'), '/').'/subscription?online_payment=return';
        $validated = $request->validated();

        $transaction = !empty($validated['saas_invoice_id'])
            ? $this->checkout->forInvoice($clinic, $user, SaasInvoice::query()->where('clinic_id', $clinic->id)->findOrFail($validated['saas_invoice_id']), $redirectUrl)
            : $this->checkout->forRenewal($clinic, $user, SubscriptionPlan::query()->where('code', $validated['plan_code'])->firstOrFail(), $validated['billing_cycle'], $redirectUrl);

        return response()->json(['data' => [
            'checkout_url' => $transaction->checkout_link,
            'tx_ref' => $transaction->tx_ref,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
        ]], 201);
    }

    /**
     * Called by the frontend when Flutterwave redirects the customer back. The query string
     * is only a hint: the transaction is verified with Flutterwave like a webhook.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'tx_ref' => ['required', 'string', 'max:80'],
            'transaction_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $transaction = FlutterwaveTransaction::query()
            ->where('clinic_id', $request->user()->clinic_id)
            ->where('tx_ref', $validated['tx_ref'])
            ->firstOrFail();

        if ($transaction->status === 'PENDING' && !empty($validated['transaction_id'])) {
            try {
                $processed = $this->checkout->process((int) $validated['transaction_id']);
                // The id given by the browser must designate this very payment.
                if ($processed !== null && $processed->id === $transaction->id) {
                    $transaction = $processed;
                }
            } catch (\Throwable $exception) {
                Log::error('Flutterwave: vérification au retour impossible.', ['tx_ref' => $transaction->tx_ref, 'erreur' => $exception->getMessage()]);
            }
        }
        $transaction->refresh();

        return response()->json(['data' => [
            'tx_ref' => $transaction->tx_ref,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'failure_reason' => $transaction->failure_reason,
            'message' => match ($transaction->status) {
                'SUCCESSFUL' => 'Paiement confirmé : votre abonnement est à jour.',
                'FAILED' => 'Le paiement n’a pas abouti : '.$transaction->failure_reason,
                'CANCELLED' => 'Ce paiement a été annulé.',
                default => 'Paiement en cours de confirmation par l’opérateur. Cette page se mettra à jour automatiquement.',
            },
        ]]);
    }
}
