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

class ClinicSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Suspended Clinic', 'city' => 'Dakar', 'country' => 'Sénégal', 'code' => 'SUSP',
            'subscription_plan' => 'BASIC', 'admin_email' => 'susp@example.test', 'status' => 'ACTIVE',
        ]);
    }

    public function test_super_admin_can_suspend_a_clinic(): void
    {
        $superAdmin = $this->userWithRole('SUPER_ADMIN', null);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/v1/clinics/{$this->clinic->id}", ['status' => 'SUSPENDED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'SUSPENDED');
    }

    public function test_clinic_admin_cannot_change_clinic_status(): void
    {
        $admin = $this->userWithRole('ADMIN_CLINIC', $this->clinic);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/clinics/{$this->clinic->id}", ['status' => 'SUSPENDED']);

        $this->assertSame('ACTIVE', $this->clinic->fresh()->status);
    }

    public function test_staff_of_a_suspended_clinic_cannot_log_in(): void
    {
        $this->userWithRole('RECEPTIONIST', $this->clinic, [
            'email' => 'staff@example.test',
            'password' => 'Password@12345',
        ]);
        $this->clinic->update(['status' => 'SUSPENDED']);

        $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.test', 'password' => 'Password@12345'])
            ->assertForbidden();
    }

    public function test_existing_sessions_lose_access_when_clinic_is_suspended(): void
    {
        $staff = $this->userWithRole('RECEPTIONIST', $this->clinic);
        $this->clinic->update(['status' => 'SUSPENDED']);

        $this->actingAs($staff->fresh(), 'sanctum')->getJson('/api/v1/patients')->assertForbidden();
    }

    public function test_profile_lists_effective_permissions(): void
    {
        $receptionist = $this->userWithRole('RECEPTIONIST', $this->clinic);

        $permissions = $this->actingAs($receptionist, 'sanctum')->getJson('/api/v1/me')->json('data.permissions');

        $this->assertContains('cancel:appointment', $permissions);
        $this->assertNotContains('read:invoice', $permissions);
    }

    private function userWithRole(string $role, ?Clinic $clinic, array $attributes = []): User
    {
        return User::factory()->create([
            'clinic_id' => $clinic?->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
            ...$attributes,
        ]);
    }
}
