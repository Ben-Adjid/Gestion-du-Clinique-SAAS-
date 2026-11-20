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
            $upgrade = ModuleAccess::upgradePlanFor($module);

            // The browser shows the upsell from these fields: the feature, the plan that includes it and where to go.
            return response()->json([
                'message' => 'Cette fonctionnalité n’est pas incluse dans la formule actuelle de votre clinique. Passez à la formule '.$upgrade.' pour l’activer.',
                'code' => 'PLAN_UPGRADE_REQUIRED',
                'feature' => $module,
                'upgrade_to' => $upgrade,
                'upgrade_url' => '/subscription',
            ], 403);
        }

        return $next($request);
    }
}
