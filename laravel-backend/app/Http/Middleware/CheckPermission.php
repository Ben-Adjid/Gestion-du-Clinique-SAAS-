<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (!$permission) {
            return $next($request);
        }

        if (!$user->role) {
            return response()->json(['message' => 'User has no role assigned'], 403);
        }

        // "a|b" grants access with either permission.
        $accepted = explode('|', $permission);
        $clinicId = $request->attributes->get('clinic_id') ?? $user->clinic_id;
        $roleHasPermission = $user->role->permissions()
            ->whereIn('permissions.name', $accepted)
            ->exists();

        $userHasPermission = $clinicId !== null && $user->permissions()
            ->wherePivot('clinic_id', $clinicId)
            ->whereIn('permissions.name', $accepted)
            ->exists();

        if (!$roleHasPermission && !$userHasPermission) {
            Log::warning("Permission denied: {$user->id} tried to access {$permission}");
            return response()->json(['message' => 'Permission denied'], 403);
        }

        return $next($request);
    }
}
