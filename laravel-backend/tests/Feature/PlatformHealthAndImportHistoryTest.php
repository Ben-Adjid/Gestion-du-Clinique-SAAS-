<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformHealthAndImportHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_super_admin_sees_database_queue_and_imaging_storage(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $superAdmin = User::factory()->create(['clinic_id' => null, 'role_id' => Role::where('name', 'SUPER_ADMIN')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/saas/health')
            ->assertOk()
            ->assertJsonPath('data.database.reachable', true)
            ->assertJsonStructure(['data' => ['database' => ['driver', 'latency_ms'], 'queue', 'imaging_storage' => ['total_bytes', 'top_clinics']]]);
    }

    public function test_only_the_platform_operator_reads_the_health_page(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $director = User::factory()->create(['clinic_id' => null, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($director, 'sanctum')->getJson('/api/v1/saas/health')->assertForbidden();
    }
}
