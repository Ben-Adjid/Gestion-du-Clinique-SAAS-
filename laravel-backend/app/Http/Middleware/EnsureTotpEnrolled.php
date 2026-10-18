<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTotpEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->requiresTotp() && !$user->totp_enabled) {
            return response()->json([
                'message' => 'La double authentification est obligatoire pour votre rôle. Activez-la dans vos paramètres.',
                'code' => 'TOTP_SETUP_REQUIRED',
            ], 403);
        }

        return $next($request);
    }
}
