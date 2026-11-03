<?php

namespace App\Models\Concerns;

use App\Support\CurrentClinic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Tenant isolation (a clinic is the tenant, i.e. the "organization"). Every select, update
 * and delete on the model is restricted to the clinic of the current request, and records
 * can only be created inside that clinic: the clinic id is filled in automatically and a
 * write into another clinic throws.
 *
 * The clinic comes from the request context set by EnsureUserClinicMatch. When a route is
 * missing that middleware, the scope falls back to the clinic of the authenticated user, so a
 * forgotten middleware can never expose another clinic. Only the platform super administrator
 * and system processes without a user (console commands, payment webhooks) run unscoped.
 */
trait BelongsToClinic
{
    public static function bootBelongsToClinic(): void
    {
        static::addGlobalScope('clinic', function (Builder $builder) {
            $clinicId = self::tenantClinicId();
            if ($clinicId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('clinic_id'), $clinicId);
            }
        });

        static::creating(function (Model $model) {
            $clinicId = self::tenantClinicId();
            if ($clinicId === null) {
                return;
            }
            if ($model->getAttribute('clinic_id') === null) {
                $model->setAttribute('clinic_id', $clinicId);
            } elseif ((int) $model->getAttribute('clinic_id') !== $clinicId) {
                throw new LogicException('Écriture refusée : la donnée appartient à une autre clinique.');
            }
        });
    }

    private static function tenantClinicId(): ?int
    {
        $clinicId = CurrentClinic::id();
        if ($clinicId !== null) {
            return $clinicId;
        }
        // Only an already resolved user is used: resolving the guard here would recurse into queries.
        $user = Auth::hasUser() ? Auth::user() : null;
        if ($user === null || $user->clinic_id === null) {
            return null;
        }

        return (int) $user->clinic_id;
    }
}
