<?php

namespace App\Http\Middleware;

use App\Models\ClinicSubscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the application when the clinic's subscription no longer gives access:
 * clinic suspended or deactivated, subscription suspended or cancelled, or period ended
 * without renewal. A PAST_DUE subscription keeps access during the grace period set in
 * config/clinic.php (saas_suspend_after_days), after which the daily billing run suspends it;
 * set CLINIC_BLOCK_PAST_DUE=true to block as soon as an invoice is overdue.
 *
 * The subscription pages themselves stay reachable so the director can pay.
 */
class EnsureSubscriptionIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->clinic_id === null || $user->role?->name === 'SUPER_ADMIN') {
            return $next($request);
        }

        $clinic = $user->clinic;
        $subscription = ClinicSubscription::query()->withoutGlobalScopes()->where('clinic_id', $user->clinic_id)->first();
        $reason = $this->blockingReason($clinic?->status, $subscription);
        if ($reason === null) {
            return $next($request);
        }

        return response()->json([
            'message' => $reason,
            'code' => 'SUBSCRIPTION_EXPIRED',
            'subscription_status' => $subscription?->status,
            'can_pay' => $user->role?->name === 'ADMIN_CLINIC',
        ], 403);
    }

    private function blockingReason(?string $clinicStatus, ?ClinicSubscription $subscription): ?string
    {
        if ($clinicStatus !== null && $clinicStatus !== 'ACTIVE') {
            return 'L’accès de la clinique est suspendu. Le directeur doit régulariser l’abonnement.';
        }
        if ($subscription === null) {
            return null;
        }
        if (in_array($subscription->status, ['SUSPENDED', 'CANCELLED'], true)) {
            return 'L’abonnement de la clinique est suspendu ou résilié.';
        }
        $periodOver = $subscription->current_period_end !== null
            && $subscription->current_period_end->lt(Carbon::today()->subDays((int) config('clinic.saas_payment_terms_days')));
        if ($periodOver && !$subscription->auto_renew) {
            return 'L’abonnement de la clinique est arrivé à échéance.';
        }
        if ($subscription->status === 'PAST_DUE' && config('clinic.block_past_due')) {
            return 'Une facture d’abonnement est impayée. Le directeur doit régulariser le paiement.';
        }

        return null;
    }
}
