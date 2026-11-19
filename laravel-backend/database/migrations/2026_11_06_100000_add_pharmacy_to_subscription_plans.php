<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Pharmacy is part of every plan: existing plans get the module added once. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')->get(['id', 'modules'])->each(function ($plan) {
            $modules = json_decode((string) $plan->modules, true) ?: [];
            if (in_array('pharmacy', $modules, true)) {
                return;
            }
            $modules[] = 'pharmacy';
            DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode(array_values($modules))]);
        });
    }

    public function down(): void
    {
        DB::table('subscription_plans')->get(['id', 'modules'])->each(function ($plan) {
            $modules = array_values(array_filter(json_decode((string) $plan->modules, true) ?: [], fn ($module) => $module !== 'pharmacy'));
            DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
        });
    }
};
