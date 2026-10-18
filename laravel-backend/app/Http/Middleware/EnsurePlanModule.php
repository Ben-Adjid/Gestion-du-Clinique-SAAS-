<?php

namespace App\Http\Middleware;

use App\Support\ModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if ($user && !ModuleAccess::planAllows($user, $module)) {
            return response()->json([
                'message' => 'Ce module n’est pas inclus dans la formule d’abonnement de votre clinique.',
                'code' => 'PLAN_MODULE_UNAVAILABLE',
            ], 403);
        }

        return $next($request);
    }
}
