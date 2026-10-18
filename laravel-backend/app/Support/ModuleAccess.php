<?php

namespace App\Support;

use App\Models\SubscriptionPlan;
use App\Models\User;

final class ModuleAccess
{
    /** Module key => permissions that grant access to it (any one is enough). */
    public const PERMISSION_MODULES = [
        'patients' => ['read:patient'],
        'appointments' => ['read:appointment'],
        'reception' => ['update:appointment', 'create:consultation'],
        'consultations' => ['read:consultation'],
        'prescriptions' => ['read:prescription'],
        'lab' => ['read:lab_request'],
        'imaging' => ['read:imaging'],
        'invoices' => ['read:invoice'],
        'pharmacy' => ['manage:pharmacy_stock', 'dispense:medication'],
        'tariffs' => ['manage:tariffs', 'manage:exams'],
        'reports' => ['view:reports'],
        'audit' => ['view:audit_logs'],
        'users' => ['manage:users'],
        'nursing' => ['read:care_task'],
        'clinics' => ['manage:clinics'],
        'saas' => ['view:saas_dashboard'],
        'subscription' => ['view:subscription'],
        'hr' => ['manage:employees', 'manage:attendance', 'manage:leaves', 'manage:payroll', 'approve:payroll'],
        'staff' => ['access:staff_space'],
        'portal' => ['access:patient_portal'],
    ];

    /** Modules that a clinic only gets when its subscription plan includes them. */
    public const PLAN_GATED = ['patients', 'appointments', 'consultations', 'prescriptions', 'nursing', 'invoices', 'lab', 'imaging', 'reports', 'hr', 'staff', 'portal'];

    /** Application module => module name used in the subscription plans. */
    public const PLAN_MODULE = ['staff' => 'hr', 'portal' => 'patient_portal'];

    /** Modules every authenticated user can open. */
    public const ALWAYS = ['notifications', 'settings'];

    /** @return list<string> */
    public static function forUser(User $user): array
    {
        $granted = self::permissionNames($user);
        if ($user->clinic && $user->clinic->status !== 'ACTIVE') {
            return in_array('view:subscription', $granted, true) ? ['subscription', 'settings'] : ['settings'];
        }
        $plan = self::planOf($user);
        $modules = [];
        foreach (self::PERMISSION_MODULES as $module => $permissions) {
            if ($user->role?->name === 'PATIENT'
                && !in_array($module, ['patients', 'appointments', 'consultations', 'prescriptions', 'portal'], true)) {
                continue;
            }
            if ($plan && in_array($module, self::PLAN_GATED, true) && !$plan->includesModule(self::PLAN_MODULE[$module] ?? $module)) {
                continue;
            }
            if (array_intersect($permissions, $granted) !== []) {
                $modules[] = $module;
            }
        }

        return [...$modules, ...self::ALWAYS];
    }

    public static function planAllows(User $user, string $module): bool
    {
        $plan = self::planOf($user);

        return $plan === null || $plan->includesModule($module);
    }

    /** @return list<string> */
    public static function effectivePermissions(User $user): array
    {
        return self::permissionNames($user);
    }

    /** @return list<string> */
    public static function permissionNames(User $user): array
    {
        $fromRole = $user->role?->permissions()->pluck('permissions.name')->all() ?? [];
        $direct = $user->permissions()
            ->wherePivot('clinic_id', $user->clinic_id)
            ->pluck('permissions.name')
            ->all();

        return array_values(array_unique([...$fromRole, ...$direct]));
    }

    private static function planOf(User $user): ?SubscriptionPlan
    {
        if ($user->clinic_id === null) {
            return null;
        }

        return $user->clinic?->subscription?->plan;
    }
}
