<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function invoices(Request $request)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['PENDING', 'PAID', 'OVERDUE', 'CANCELLED'])],
            'clinic_id' => ['sometimes', 'nullable', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        return response()->json(SaasInvoice::query()
            ->with(['clinic:id,name,code', 'payments'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['clinic_id'] ?? null, fn ($query, $clinicId) => $query->where('clinic_id', $clinicId))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('clinic', fn ($clinics) => $clinics->where('name', 'like', "%{$search}%"))))
            ->orderByRaw("CASE status WHEN 'OVERDUE' THEN 0 WHEN 'PENDING' THEN 1 ELSE 2 END")
            ->latest('issued_at')
            ->paginate($validated['per_page'] ?? 20));
    }

    public function recordPayment(Request $request, SaasInvoice $invoice)
    {
        $validated = $request->validate($this->paymentRules(declaration: false));
        $payment = $this->subscriptions->recordPayment($invoice, $validated, $request->user());

        return response()->json([
            'data' => $payment->load('invoice'),
            'message' => 'Paiement enregistré.',
        ], 201);
    }

    public function payments(Request $request)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['PENDING', 'CONFIRMED', 'REJECTED'])],
        ]);

        return response()->json(SaasPayment::query()
            ->with(['clinic:id,name,code', 'invoice:id,number,amount,status,due_date', 'declarer:id,name'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate(30));
    }

    public function confirm(Request $request, SaasPayment $payment)
    {
        $payment->load('invoice', 'clinic');

        return response()->json([
            'data' => $this->subscriptions->confirmPayment($payment, $request->user()),
            'message' => 'Paiement validé.',
        ]);
    }

    public function reject(Request $request, SaasPayment $payment)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $payment->load('invoice', 'clinic');

        return response()->json([
            'data' => $this->subscriptions->rejectPayment($payment, $validated['reason'], $request->user()),
            'message' => 'Paiement refusé. La clinique a été notifiée.',
        ]);
    }

    public function runBilling()
    {
        $counters = $this->subscriptions->runBilling();

        return response()->json([
            'data' => $counters,
            'message' => "Facturation exécutée : {$counters['renewed']} renouvellement(s), {$counters['overdue']} facture(s) en retard, {$counters['suspended']} suspension(s).",
        ]);
    }

    /** @return array<string, mixed> */
    public static function paymentRules(bool $declaration): array
    {
        $methods = $declaration ? ['ORANGE_MONEY', 'MOOV_MONEY', 'AIRTEL_MONEY', 'BANK_TRANSFER'] : SaasPayment::METHODS;

        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::in($methods)],
            'reference' => [
                Rule::requiredIf(fn () => $declaration || in_array(request('method'), ['ORANGE_MONEY', 'MOOV_MONEY', 'AIRTEL_MONEY', 'BANK_TRANSFER'], true)),
                'nullable',
                'string',
                'max:120',
                Rule::unique('saas_payments', 'reference')->where(fn ($query) => $query->where('method', request('method'))->whereNot('status', 'REJECTED')),
            ],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
