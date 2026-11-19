<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every plan offers a 30-day free trial. Subscriptions already started keep their own dates. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')->update(['trial_days' => 30]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('code', 'BASIC')->update(['trial_days' => 14]);
        DB::table('subscription_plans')->where('code', 'PRO')->update(['trial_days' => 14]);
    }
};
