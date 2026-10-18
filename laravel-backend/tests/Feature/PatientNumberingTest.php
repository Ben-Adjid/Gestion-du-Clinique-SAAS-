<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_numbers_follow_the_format_and_survive_archiving(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Hariri', 'city' => 'Ndjamena', 'country' => 'Tchad', 'code' => 'HARIRI',
            'subscription_plan' => 'BASIC', 'admin_email' => 'hariri@example.test', 'status' => 'ACTIVE',
        ]);
        $admin = User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'),
        ]);
        $create = fn () => $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/patients', ['first_name' => 'Amina', 'last_name' => 'Saleh', 'confirm_not_duplicate' => true])
            ->assertCreated()
            ->json('data');

        $year = now()->year;
        $first = $create();
        $second = $create();
        $this->assertSame("HARIRI-{$year}-000001", $first['patient_unique_id']);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/patients/{$second['id']}")->assertNoContent();

        $this->assertSame("HARIRI-{$year}-000003", $create()['patient_unique_id']);
    }
}
