<?php

namespace App\Models\Concerns;

use App\Support\CurrentClinic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Every query on the model is restricted to the clinic of the current request,
 * and records can only be created inside that clinic.
 */
trait BelongsToClinic
{
    public static function bootBelongsToClinic(): void
    {
        static::addGlobalScope('clinic', function (Builder $builder) {
            $clinicId = CurrentClinic::id();
            if ($clinicId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('clinic_id'), $clinicId);
            }
        });

        static::creating(function (Model $model) {
            $clinicId = CurrentClinic::id();
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
}
