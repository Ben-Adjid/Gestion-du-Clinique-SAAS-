<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Clinic;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogAuditActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = match ($request->method()) {
            'GET', 'HEAD' => 'READ',
            'POST' => 'CREATE',
            'PUT', 'PATCH' => 'UPDATE',
            'DELETE' => 'DELETE',
            default => null,
        };
        $user = $request->user();
        $route = $request->route();
        $controller = $route?->getControllerClass();
        $resource = $controller
            ? Str::upper(Str::before(class_basename($controller), 'Controller'))
            : 'API';
        $resourceId = null;
        $clinicId = $request->attributes->get('clinic_id') ?? $user?->clinic_id;

        foreach ($route?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                $resourceId = $parameter->getKey();
                $clinicId = $parameter instanceof Clinic
                    ? $parameter->getKey()
                    : ($parameter->getAttribute('clinic_id') ?? $clinicId);
                break;
            }
            if (is_numeric($parameter)) {
                $resourceId = (int) $parameter;
            }
        }

        return DB::transaction(function () use ($action, $clinicId, $next, $request, $resource, &$resourceId, $user): Response {
            $response = $next($request);
            if ($action === 'CREATE' && ($resourceId === null || !$clinicId) && $response->isSuccessful()) {
                $payload = json_decode($response->getContent(), true);
                $resourceId ??= $payload['data']['id'] ?? null;
                $clinicId ??= $payload['data']['clinic_id'] ?? ($resource === 'CLINIC' ? $resourceId : null);
            }
            if (!$action || !$user || !$clinicId || !$response->isSuccessful()) {
                return $response;
            }

            $changedFields = $action === 'CREATE' || $action === 'UPDATE'
                ? array_values(array_diff(array_keys($request->all()), ['password', 'totp_code', 'current_password']))
                : null;

            AuditLog::create([
                'clinic_id' => $clinicId,
                'user_id' => $user->id,
                'action' => $action,
                'resource' => $resource,
                'resource_id' => $resourceId,
                'after_data' => $changedFields ? ['changed_fields' => $changedFields] : null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return $response;
        });
    }
}
