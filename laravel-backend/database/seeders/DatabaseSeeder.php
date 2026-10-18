<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles and permissions first
        $seeders = [
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            ClinicSeeder::class,
            SubscriptionPlanSeeder::class,
            Cim10Seeder::class,
        ];
        if (app()->environment(['local', 'testing'])) {
            $seeders[] = ClinicUsersSeeder::class;
        }
        $this->call($seeders);
    }
}
