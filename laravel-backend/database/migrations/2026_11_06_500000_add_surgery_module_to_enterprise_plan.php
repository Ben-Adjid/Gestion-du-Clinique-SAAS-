<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The operating theatre module belongs to the ENTERPRISE plan. */
return new class extends Migration
{
    public function up(): void
    {
        $this->setSurgery(true);
    }

    public function down(): void
    {
        $this->setSurgery(false);
    }

    private function setSurgery(bool $included): void
    {
        DB::table('subscription_plans')->where('code', 'ENTERPRISE')->get(['id', 'modules'])->each(function ($plan) use ($included) {
            $modules = array_values(array_filter(json_decode((string) $plan->modules, true) ?: [], fn ($module) => $module !== 'surgery'));
            if ($included) {
                $modules[] = 'surgery';
            }
            DB::table('subscription_plans')->where('id', $plan->id)->update(['modules' => json_encode($modules)]);
        });
    }
};
