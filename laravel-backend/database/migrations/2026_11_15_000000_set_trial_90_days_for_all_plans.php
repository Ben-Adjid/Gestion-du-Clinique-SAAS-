<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every new clinic gets a 3-month free trial (90 days). Subscriptions already started keep their dates. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')->update(['trial_days' => 90]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')->update(['trial_days' => 30]);
    }
};
