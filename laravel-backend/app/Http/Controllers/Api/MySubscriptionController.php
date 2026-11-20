<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Saas\BillingController;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\SaasInvoice;
use App\Services\SubscriptionService;
use App\Support\PlanLimits;
use Illuminate\Http\Request;

class MySubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function show(Request $request)
    {
        $clinic = $request->user()->clinic;
        $subscription = $clinic?->subscription()->with('plan')->first();

        return response()->json(['data' => [
            'clinic' => $clinic?->only(['id', 'name', 'code', 'status']),
            'subscription' => $subscription,
            'usage' => [
                'active_users' => PlanLimits::activeStaff($clinic?->id),
                'max_users' => $subscription?->plan?->max_users,
                'patients' => Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic?->id)->whereNull('deleted_at')->count(),
                'max_patients' => $subscription?->plan?->max_patients,
            ],
            'invoices' => SaasInvoice::query()
                ->where('clinic_id', $clinic?->id)
                ->with(['payments' => fn ($payments) => $payments->latest()])
                ->latest('issued_at')
                ->limit(24)
                ->get(),
            'payment_instructions' => config('clinic.saas_payment_instructions'),
            'online_payment_enabled' => (bool) config('services.online_payments.enabled') && app(\App\Services\Payments\FlutterwaveClient::class)->isConfigured(),
            'online_payments' => \App\Models\FlutterwaveTransaction::query()
                ->where('clinic_id', $clinic?->id)
                ->latest()
                ->limit(10)
                ->get(['id', 'purpose', 'tx_ref', 'amount', 'currency', 'status', 'payment_type', 'failure_reason', 'created_at', 'verified_at']),
        ]]);
    }

    public function declarePayment(Request $request)
    {
        $validated = $request->validate([
            'saas_invoice_id' => ['required', 'integer'],
            ...BillingController::paymentRules(declaration: true),
        ]);
        $invoice = SaasInvoice::query()
            ->whereKey($validated['saas_invoice_id'])
            ->where('clinic_id', $request->user()->clinic_id)
            ->firstOrFail();
        unset($validated['saas_invoice_id']);

        $payment = $this->subscriptions->declarePayment($invoice, $validated, $request->user());

        return response()->json([
            'data' => $payment,
            'message' => 'Paiement déclaré. Il sera validé par l’équipe Clinique+ après vérification de la référence.',
        ], 201);
    }
}
