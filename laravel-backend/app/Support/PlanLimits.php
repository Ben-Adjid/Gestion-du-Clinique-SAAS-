<?php

namespace App\Support;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class PlanLimits
{
    public static function assertCanAddUser(?int $clinicId, string $field = 'role_id'): void
    {
        $plan = self::plan($clinicId);
        if ($plan?->max_users === null) {
            return;
        }
        if (self::activeStaff($clinicId) >= $plan->max_users) {
            throw ValidationException::withMessages([
                $field => "La formule {$plan->name} est limitée à {$plan->max_users} utilisateurs actifs. Passez à une formule supérieure ou désactivez un compte.",
            ]);
        }
    }

    public static function assertCanAddPatient(?int $clinicId): void
    {
        $plan = self::plan($clinicId);
        if ($plan?->max_patients === null) {
            return;
        }
        $patients = Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereNull('deleted_at')->count();
        if ($patients >= $plan->max_patients) {
            throw ValidationException::withMessages([
                'first_name' => "La formule {$plan->name} est limitée à {$plan->max_patients} dossiers patients. Contactez Clinique+ pour changer de formule.",
            ]);
        }
    }

    /** Staff accounts count against the plan; patient portal accounts do not. */
    public static function activeStaff(?int $clinicId): int
    {
        return User::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'ACTIVE')
            ->whereDoesntHave('role', fn ($roles) => $roles->where('name', 'PATIENT'))
            ->count();
    }

    private static function plan(?int $clinicId)
    {
        return $clinicId === null ? null : Clinic::query()->find($clinicId)?->subscription?->plan;
    }
}
