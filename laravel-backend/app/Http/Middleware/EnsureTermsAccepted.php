<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No access to the clinic's records before the clinic accepted the current version of the terms
 * of use (CGU). The director accepts them; the platform administrator only sees the status.
 */
class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('clinic.terms_enforced')) {
            return $next($request);
        }
        $user = $request->user();
        if ($user === null || $user->clinic_id === null || $user->role?->name === 'SUPER_ADMIN') {
            return $next($request);
        }

        $clinic = $user->clinic;
        $accepted = $clinic !== null
            && $clinic->terms_accepted_at !== null
            && $clinic->terms_version === config('clinic.terms_version');
        if ($accepted) {
            return $next($request);
        }

        return response()->json([
            'message' => 'La clinique doit accepter les conditions générales d’utilisation (version '.config('clinic.terms_version').') avant d’accéder aux dossiers. Le directeur peut les accepter dans ses paramètres.',
            'code' => 'TERMS_REQUIRED',
            'terms_version' => config('clinic.terms_version'),
            'can_accept' => $user->role?->name === 'ADMIN_CLINIC',
        ], 403);
    }
}
