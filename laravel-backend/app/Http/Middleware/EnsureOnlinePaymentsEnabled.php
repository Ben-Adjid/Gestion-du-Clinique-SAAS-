<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Online payment of the clinic subscription (card, mobile money, provider checkout) is switched off for now.
 * The code stays in place: ONLINE_PAYMENTS_ENABLED turns it back on.
 */
class EnsureOnlinePaymentsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('services.online_payments.enabled')) {
            return response()->json([
                'message' => 'Le paiement en ligne n’est pas disponible pour le moment. Réglez votre abonnement auprès de Clinique+.',
            ], 404);
        }

        return $next($request);
    }
}
