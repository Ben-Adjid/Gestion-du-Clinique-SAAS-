<?php

namespace App\Http\Middleware;

use App\Support\CurrentClinic;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserClinicMatch
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Authentification requise.'], 401);
        }

        // A clinic selector is available only to the super administrator.
        $clinicId = $request->header('X-Clinic-ID')
                    ?? $request->input('clinic_id')
                    ?? $user->clinic_id;

        if ($user->role && $user->role->name === 'SUPER_ADMIN') {
            if ($clinicId !== null && !\App\Models\Clinic::whereKey($clinicId)->exists()) {
                return response()->json(['message' => 'Clinique introuvable.'], 404);
            }
            $request->attributes->add(['clinic_id' => $clinicId]);
            CurrentClinic::set($clinicId === null ? null : (int) $clinicId);
            return $next($request);
        }

        // Subscription state (suspended clinic, expired plan) is enforced by EnsureSubscriptionIsActive,
        // which the subscription routes (mode "allow-suspended") leave out so the director can pay.

        // Other users can only access their own clinic
        if ($clinicId && $clinicId != $user->clinic_id) {
            \Illuminate\Support\Facades\Log::warning("Clinic access denied: User {$user->id} tried to access clinic {$clinicId}");
            return response()->json(['message' => 'Accès à cette clinique refusé.'], 403);
        }

        $request->attributes->add(['clinic_id' => $user->clinic_id]);
        CurrentClinic::set($user->clinic_id === null ? null : (int) $user->clinic_id);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        CurrentClinic::clear();
    }
}
