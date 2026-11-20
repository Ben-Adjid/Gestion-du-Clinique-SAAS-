<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns the modules of the subscription plans with the offer: BASIC (Standard), PRO (VIP) and ENTERPRISE
 * (large hospitals). Clinics read their modules from their plan row, so updating the rows aligns existing clinics.
 * Literal lists on purpose: a migration must not depend on the seeder class.
 */
return new class extends Migration
{
    private const MODULES = [
        'BASIC' => ['patients', 'appointments', 'consultations', 'prescriptions', 'nursing', 'invoices'],
        'PRO' => ['patients', 'appointments', 'consultations', 'prescriptions', 'nursing', 'invoices', 'beds', 'lab', 'imaging', 'pharmacy', 'surgery'],
        'ENTERPRISE' => ['patients', 'appointments', 'consultations', 'prescriptions', 'nursing', 'invoices', 'beds', 'lab', 'imaging', 'pharmacy', 'surgery', 'specialties', 'reports', 'hr', 'patient_portal'],
    ];

    public function up(): void
    {
        foreach (self::MODULES as $code => $modules) {
            DB::table('subscription_plans')->where('code', $code)->update(['modules' => json_encode($modules)]);
        }
    }

    public function down(): void
    {
        // The previous module lists are not restored automatically: the offer is the source of truth.
    }
};
