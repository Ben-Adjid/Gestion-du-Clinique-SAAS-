<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\FlutterwaveTransaction;
use App\Models\Patient;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\PlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function start(Clinic $clinic, SubscriptionPlan $plan, string $billingCycle, bool $withTrial, ?User $actor = null): ClinicSubscription
    {
        return DB::transaction(function () use ($clinic, $plan, $billingCycle, $withTrial, $actor) {
            $today = Carbon::today();
            $trial = $withTrial && $plan->trial_days > 0;
            $periodEnd = $trial ? $today->copy()->addDays($plan->trial_days - 1) : $this->periodEnd($today, $billingCycle);

            $subscription = ClinicSubscription::create([
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'status' => $trial ? 'TRIAL' : 'ACTIVE',
                'price' => $plan->priceFor($billingCycle),
                'current_period_start' => $today,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $trial ? $periodEnd : null,
                'grace_days' => (int) config('clinic.saas_payment_terms_days'),
            ]);
            $clinic->update(['subscription_plan' => $plan->code]);

            $this->event($clinic, 'CREATED', $trial
                ? "Abonnement {$plan->name} démarré avec {$plan->trial_days} jours d’essai."
                : "Abonnement {$plan->name} démarré ({$this->cycleLabel($billingCycle)}).", $actor);

            if (!$trial) {
                $this->issueInvoice($subscription, $today, $periodEnd);
            }

            return $subscription;
        });
    }

    public function issueInvoice(ClinicSubscription $subscription, Carbon $periodStart, Carbon $periodEnd): SaasInvoice
    {
        $subscription->loadMissing('plan', 'clinic');

        $invoice = SaasInvoice::create([
            'clinic_id' => $subscription->clinic_id,
            'subscription_id' => $subscription->id,
            'number' => $this->nextInvoiceNumber($periodStart->year),
            'plan_code' => $subscription->plan->code,
            'billing_cycle' => $subscription->billing_cycle,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'amount' => $subscription->price,
            'currency' => $subscription->plan->currency,
            'status' => 'PENDING',
            'due_date' => $periodStart->copy()->addDays($subscription->grace_days),
            'issued_at' => now(),
        ]);

        $this->notifyClinicAdmins($subscription->clinic, 'Nouvelle facture d’abonnement',
            "Facture {$invoice->number} de ".number_format($invoice->amount, 0, ',', ' ')." FCFA à régler avant le {$invoice->due_date->format('d/m/Y')}.");

        return $invoice;
    }

    /** Daily billing run: renewals, overdue invoices, suspensions. Returns counters for reporting. */
    public function runBilling(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $counters = ['renewed' => 0, 'ended' => 0, 'overdue' => 0, 'suspended' => 0];

        ClinicSubscription::query()
            ->whereIn('status', ['TRIAL', 'ACTIVE', 'PAST_DUE'])
            ->whereDate('current_period_end', '<', $today)
            ->with(['plan', 'clinic'])
            ->each(function (ClinicSubscription $subscription) use ($today, &$counters) {
                DB::transaction(function () use ($subscription, $today, &$counters) {
                    if (!$subscription->auto_renew) {
                        $subscription->update(['status' => 'CANCELLED']);
                        $subscription->clinic->update(['status' => 'INACTIVE']);
                        $this->event($subscription->clinic, 'ENDED', 'Abonnement arrivé à échéance sans renouvellement : clinique désactivée.');
                        $counters['ended']++;

                        return;
                    }
                    while ($subscription->current_period_end->lt($today)) {
                        $start = $subscription->current_period_end->copy()->addDay();
                        $end = $this->periodEnd($start, $subscription->billing_cycle);
                        $wasTrial = $subscription->status === 'TRIAL';
                        $subscription->update([
                            'status' => $wasTrial ? 'ACTIVE' : $subscription->status,
                            'current_period_start' => $start,
                            'current_period_end' => $end,
                            'price' => $subscription->plan->priceFor($subscription->billing_cycle),
                        ]);
                        $this->issueInvoice($subscription, $start, $end);
                        if ($wasTrial) {
                            $this->notifyClinicAdmins($subscription->clinic, 'Fin de l’essai gratuit',
                                'Votre essai de 3 mois est terminé. Réglez la première facture avant le '.$end->copy()->subDays(0)->format('d/m/Y').' pour garder l’accès à Clinique+.');
                        }
                        $this->event($subscription->clinic, 'RENEWED', $wasTrial
                            ? 'Fin de l’essai : première facture émise.'
                            : "Renouvellement du {$start->format('d/m/Y')} au {$end->format('d/m/Y')}.");
                        $counters['renewed']++;
                    }
                });
            });

        SaasInvoice::query()
            ->where('status', 'PENDING')
            ->whereDate('due_date', '<', $today)
            ->with('subscription.clinic')
            ->each(function (SaasInvoice $invoice) use (&$counters) {
                $invoice->update(['status' => 'OVERDUE']);
                if ($invoice->subscription->status === 'ACTIVE') {
                    $invoice->subscription->update(['status' => 'PAST_DUE']);
                }
                $this->notifyClinicAdmins($invoice->subscription->clinic, 'Facture d’abonnement en retard',
                    "La facture {$invoice->number} est en retard. Sans paiement, la clinique sera suspendue.");
                $this->event($invoice->subscription->clinic, 'INVOICE_OVERDUE', "Facture {$invoice->number} en retard de paiement.");
                $counters['overdue']++;
            });

        $suspendBefore = $today->copy()->subDays((int) config('clinic.saas_suspend_after_days'));
        ClinicSubscription::query()
            ->where('status', 'PAST_DUE')
            ->whereHas('invoices', fn ($invoices) => $invoices->where('status', 'OVERDUE')->whereDate('due_date', '<', $suspendBefore))
            ->with('clinic')
            ->each(function (ClinicSubscription $subscription) use (&$counters) {
                $subscription->update(['status' => 'SUSPENDED']);
                $subscription->clinic->update(['status' => 'SUSPENDED']);
                $this->event($subscription->clinic, 'SUSPENDED', 'Clinique suspendue automatiquement pour impayé.');
                $counters['suspended']++;
            });

        return $counters;
    }

    /** @param array{amount: float|int|string, method: string, reference?: ?string, paid_on: string, notes?: ?string} $data */
    public function recordPayment(SaasInvoice $invoice, array $data, User $actor): SaasPayment
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $this->assertPayable($invoice, (float) $data['amount']);
            $payment = SaasPayment::create([
                ...$data,
                'clinic_id' => $invoice->clinic_id,
                'saas_invoice_id' => $invoice->id,
                'status' => 'CONFIRMED',
                'declared_by' => $actor->id,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
            ]);
            $this->event($invoice->clinic, 'PAYMENT', "Paiement de ".number_format((float) $data['amount'], 0, ',', ' ')." FCFA enregistré sur {$invoice->number}.", $actor);
            $this->settle($invoice->fresh());

            return $payment;
        });
    }

    /** @param array{amount: float|int|string, method: string, reference: string, paid_on: string, notes?: ?string} $data */
    public function declarePayment(SaasInvoice $invoice, array $data, User $actor): SaasPayment
    {
        $this->assertPayable($invoice, (float) $data['amount']);
        $payment = SaasPayment::create([
            ...$data,
            'clinic_id' => $invoice->clinic_id,
            'saas_invoice_id' => $invoice->id,
            'status' => 'PENDING',
            'declared_by' => $actor->id,
        ]);
        $this->event($invoice->clinic, 'PAYMENT_DECLARED', "Paiement déclaré sur {$invoice->number} (référence {$payment->reference}), en attente de validation.", $actor);

        return $payment;
    }

    public function confirmPayment(SaasPayment $payment, User $actor): SaasPayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            if ($payment->status !== 'PENDING') {
                throw ValidationException::withMessages(['payment' => 'Ce paiement a déjà été traité.']);
            }
            $this->assertPayable($payment->invoice, $payment->amount);
            $payment->update(['status' => 'CONFIRMED', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
            $this->event($payment->clinic, 'PAYMENT', "Paiement {$payment->reference} validé sur {$payment->invoice->number}.", $actor);
            $this->settle($payment->invoice->fresh());

            return $payment->fresh();
        });
    }

    public function rejectPayment(SaasPayment $payment, string $reason, User $actor): SaasPayment
    {
        if ($payment->status !== 'PENDING') {
            throw ValidationException::withMessages(['payment' => 'Ce paiement a déjà été traité.']);
        }
        $payment->update(['status' => 'REJECTED', 'rejection_reason' => $reason, 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
        $this->event($payment->clinic, 'PAYMENT_REJECTED', "Paiement {$payment->reference} refusé : {$reason}", $actor);
        $this->notifyClinicAdmins($payment->clinic, 'Paiement d’abonnement refusé', "Votre paiement {$payment->reference} a été refusé : {$reason}");

        return $payment->fresh();
    }

    /** The clinic's current users and patients must fit within the plan limits. */
    public function assertPlanFits(Clinic $clinic, SubscriptionPlan $plan): void
    {
        $activeUsers = PlanLimits::activeStaff($clinic->id);
        $patients = Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereNull('deleted_at')->count();
        $errors = [];
        if ($plan->max_users !== null && $activeUsers > $plan->max_users) {
            $errors['plan_code'][] = "La formule {$plan->name} est limitée à {$plan->max_users} utilisateurs actifs ; la clinique en a {$activeUsers}.";
        }
        if ($plan->max_patients !== null && $patients > $plan->max_patients) {
            $errors['plan_code'][] = "La formule {$plan->name} est limitée à {$plan->max_patients} patients ; la clinique en a {$patients}.";
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Applies a Flutterwave payment that has been verified server-to-server. Called once per
     * transaction (the caller holds a lock and checks the transaction status).
     */
    public function applyOnlinePayment(FlutterwaveTransaction $transaction): void
    {
        $subscription = ClinicSubscription::query()->with(['plan', 'clinic'])->lockForUpdate()->findOrFail($transaction->subscription_id);
        $clinic = $subscription->clinic;

        if ($transaction->purpose === 'INVOICE') {
            $invoice = SaasInvoice::query()->lockForUpdate()->findOrFail($transaction->saas_invoice_id);
        } else {
            // A paid renewal buys a full period: it starts today when the subscription is in
            // trial, ended or suspended, otherwise right after the current period.
            $plan = SubscriptionPlan::query()->findOrFail($transaction->plan_id);
            $today = Carbon::today();
            $restart = in_array($subscription->status, ['TRIAL', 'SUSPENDED', 'CANCELLED'], true)
                || $subscription->current_period_end->lt($today);
            $start = $restart ? $today : $subscription->current_period_end->copy()->addDay();
            $end = $this->periodEnd($start, $transaction->billing_cycle);
            $subscription->update([
                'plan_id' => $plan->id,
                'billing_cycle' => $transaction->billing_cycle,
                'price' => $plan->priceFor($transaction->billing_cycle),
                'currency' => $plan->currency,
                'current_period_start' => $restart ? $start : $subscription->current_period_start,
                'current_period_end' => $end,
                'trial_ends_at' => $subscription->status === 'TRIAL' ? $today->copy()->subDay() : $subscription->trial_ends_at,
                'auto_renew' => true,
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);
            $clinic->update(['subscription_plan' => $plan->code]);
            $invoice = SaasInvoice::create([
                'clinic_id' => $clinic->id,
                'subscription_id' => $subscription->id,
                'number' => $this->nextInvoiceNumber($start->year),
                'plan_code' => $plan->code,
                'billing_cycle' => $transaction->billing_cycle,
                'period_start' => $start,
                'period_end' => $end,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'status' => 'PENDING',
                'due_date' => $start,
                'issued_at' => now(),
            ]);
            $this->event($clinic, 'RENEWED', "Période payée en ligne du {$start->format('d/m/Y')} au {$end->format('d/m/Y')} (formule {$plan->name}).");
        }

        SaasPayment::create([
            'clinic_id' => $clinic->id,
            'saas_invoice_id' => $invoice->id,
            'flutterwave_transaction_id' => $transaction->id,
            'amount' => min($transaction->amount, max($invoice->balance, 0)),
            'method' => 'FLUTTERWAVE',
            'reference' => $transaction->flw_ref ?? (string) $transaction->flw_transaction_id,
            'paid_on' => Carbon::today(),
            'status' => 'CONFIRMED',
            'declared_by' => $transaction->user_id,
            'confirmed_at' => now(),
            'notes' => 'Paiement en ligne Flutterwave ('.($transaction->payment_type ?? 'inconnu').').',
        ]);
        $subscription->update(['flw_transaction_id' => $transaction->flw_transaction_id, 'flw_ref' => $transaction->flw_ref]);
        $this->event($clinic, 'PAYMENT', 'Paiement en ligne de '.number_format($transaction->amount, 0, ',', ' ')." {$transaction->currency} confirmé par Flutterwave (facture {$invoice->number}).");

        $invoice = $invoice->fresh();
        if ($invoice->balance <= 0) {
            $invoice->update(['status' => 'PAID', 'paid_at' => now()]);
        }
        $stillOverdue = $subscription->invoices()->where('status', 'OVERDUE')->exists();
        if (!$stillOverdue && in_array($subscription->fresh()->status, ['TRIAL', 'PAST_DUE', 'SUSPENDED', 'CANCELLED'], true)) {
            $subscription->update(['status' => 'ACTIVE']);
        }
        if (!$stillOverdue && $clinic->status !== 'ACTIVE') {
            $clinic->update(['status' => 'ACTIVE']);
            $this->event($clinic, 'REACTIVATED', 'Clinique réactivée automatiquement après paiement en ligne.');
        }
        $this->notifyClinicAdmins($clinic, 'Paiement d’abonnement reçu', 'Votre paiement en ligne de '.number_format($transaction->amount, 0, ',', ' ')." {$transaction->currency} a été confirmé. Merci !");
    }

    /** A failed attempt only matters once the period is over: the subscription becomes past due. */
    public function onlinePaymentFailed(FlutterwaveTransaction $transaction): void
    {
        $subscription = ClinicSubscription::query()->with('clinic')->find($transaction->subscription_id);
        if ($subscription && in_array($subscription->status, ['TRIAL', 'ACTIVE'], true) && $subscription->current_period_end->lt(Carbon::today())) {
            $subscription->update(['status' => 'PAST_DUE']);
            $this->event($subscription->clinic, 'PAYMENT_FAILED', 'Paiement en ligne échoué alors que la période est terminée : abonnement en retard de paiement.');
        } elseif ($subscription) {
            $this->event($subscription->clinic, 'PAYMENT_FAILED', "Tentative de paiement en ligne non aboutie ({$transaction->failure_reason}).");
        }
    }

    public function changePlan(ClinicSubscription $subscription, SubscriptionPlan $plan, string $billingCycle, User $actor): ClinicSubscription
    {
        $clinic = $subscription->clinic;
        $this->assertPlanFits($clinic, $plan);

        $previous = $subscription->plan->name;
        $subscription->update([
            'plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'price' => $plan->priceFor($billingCycle),
        ]);
        $clinic->update(['subscription_plan' => $plan->code]);
        $this->event($clinic, 'PLAN_CHANGED', "Formule {$previous} → {$plan->name} ({$this->cycleLabel($billingCycle)}). Le nouveau tarif s’applique à la prochaine facture.", $actor);

        return $subscription->fresh(['plan']);
    }

    public function cancel(ClinicSubscription $subscription, string $reason, User $actor): ClinicSubscription
    {
        $subscription->update(['auto_renew' => false, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        $this->event($subscription->clinic, 'CANCELLATION_SCHEDULED',
            "Résiliation demandée : l’accès prendra fin le {$subscription->current_period_end->format('d/m/Y')}. Motif : {$reason}", $actor);

        return $subscription->fresh(['plan']);
    }

    public function resume(ClinicSubscription $subscription, User $actor): ClinicSubscription
    {
        $subscription->update(['auto_renew' => true, 'cancelled_at' => null, 'cancellation_reason' => null]);
        $this->event($subscription->clinic, 'CANCELLATION_REVOKED', 'Résiliation annulée : l’abonnement sera renouvelé.', $actor);

        return $subscription->fresh(['plan']);
    }

    public function suspend(Clinic $clinic, string $reason, User $actor): void
    {
        $clinic->update(['status' => 'SUSPENDED']);
        $this->event($clinic, 'SUSPENDED', "Suspension manuelle : {$reason}", $actor);
    }

    public function reactivate(Clinic $clinic, User $actor): void
    {
        $subscription = $clinic->subscription;
        if ($subscription && $subscription->invoices()->where('status', 'OVERDUE')->exists()) {
            throw ValidationException::withMessages(['clinic' => 'Des factures d’abonnement sont en retard : enregistrez le paiement avant de réactiver.']);
        }
        if ($subscription && $subscription->status === 'SUSPENDED') {
            $subscription->update(['status' => 'ACTIVE']);
        }
        $clinic->update(['status' => 'ACTIVE']);
        $this->event($clinic, 'REACTIVATED', 'Clinique réactivée.', $actor);
    }

    private function settle(SaasInvoice $invoice): void
    {
        if ($invoice->balance > 0) {
            return;
        }
        $invoice->update(['status' => 'PAID', 'paid_at' => now()]);
        $subscription = $invoice->subscription()->with('clinic')->first();
        $stillOverdue = $subscription->invoices()->where('status', 'OVERDUE')->exists();
        if ($stillOverdue || !in_array($subscription->status, ['PAST_DUE', 'SUSPENDED'], true)) {
            return;
        }
        $wasSuspended = $subscription->status === 'SUSPENDED';
        $subscription->update(['status' => 'ACTIVE']);
        if ($wasSuspended && $subscription->clinic->status === 'SUSPENDED') {
            $subscription->clinic->update(['status' => 'ACTIVE']);
            $this->event($subscription->clinic, 'REACTIVATED', 'Clinique réactivée automatiquement après paiement.');
        }
    }

    private function assertPayable(SaasInvoice $invoice, float $amount): void
    {
        if (in_array($invoice->status, ['PAID', 'CANCELLED'], true)) {
            throw ValidationException::withMessages(['amount' => 'Cette facture est déjà réglée ou annulée.']);
        }
        if ($amount <= 0 || $amount > $invoice->balance + 0.001) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être positif et ne pas dépasser le reste à payer ('.number_format($invoice->balance, 0, ',', ' ').' FCFA).']);
        }
    }

    private function nextInvoiceNumber(int $year): string
    {
        $last = SaasInvoice::query()->withoutGlobalScopes()->where('number', 'like', "SAAS-{$year}-%")->lockForUpdate()->pluck('number')
            ->map(fn (string $number) => (int) substr($number, strlen("SAAS-{$year}-")))
            ->max() ?? 0;

        return sprintf('SAAS-%d-%06d', $year, $last + 1);
    }

    private function periodEnd(Carbon $start, string $billingCycle): Carbon
    {
        return ($billingCycle === 'YEARLY' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow())->subDay();
    }

    private function cycleLabel(string $billingCycle): string
    {
        return $billingCycle === 'YEARLY' ? 'facturation annuelle' : 'facturation mensuelle';
    }

    private function event(Clinic $clinic, string $type, string $description, ?User $actor = null): void
    {
        SubscriptionEvent::create([
            'clinic_id' => $clinic->id,
            'type' => $type,
            'description' => $description,
            'user_id' => $actor?->id,
        ]);
    }

    private function notifyClinicAdmins(Clinic $clinic, string $title, string $message): void
    {
        User::query()
            ->where('clinic_id', $clinic->id)
            ->where('status', 'ACTIVE')
            ->whereHas('role', fn ($roles) => $roles->where('name', 'ADMIN_CLINIC'))
            ->pluck('id')
            ->each(fn (int $userId) => UserNotification::query()->withoutGlobalScopes()->create([
                'clinic_id' => $clinic->id,
                'user_id' => $userId,
                'type' => 'SUBSCRIPTION',
                'title' => $title,
                'message' => $message,
            ]));
    }
}
