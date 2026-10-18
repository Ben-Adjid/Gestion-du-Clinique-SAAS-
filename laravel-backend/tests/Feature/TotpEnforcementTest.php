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

class TotpEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('clinic.totp_required_roles', ['DOCTOR', 'CASHIER']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Totp Clinic', 'city' => 'Dakar', 'country' => 'Sénégal', 'code' => 'TOTP',
            'subscription_plan' => 'BASIC', 'admin_email' => 'totp@example.test', 'status' => 'ACTIVE',
        ]);
    }

    public function test_doctor_without_2fa_is_blocked_from_clinical_data(): void
    {
        $doctor = $this->userWithRole('DOCTOR', totp: false);

        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'TOTP_SETUP_REQUIRED');
        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/patients')->assertForbidden();
    }

    public function test_profile_stays_reachable_and_reports_the_requirement(): void
    {
        $doctor = $this->userWithRole('DOCTOR', totp: false);

        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.totp_required', true)
            ->assertJsonPath('data.totp_enabled', false);
    }

    public function test_doctor_with_2fa_has_access(): void
    {
        $doctor = $this->userWithRole('DOCTOR', totp: true);

        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_roles_outside_the_list_are_not_affected(): void
    {
        $nurse = $this->userWithRole('NURSE', totp: false);

        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_mandatory_2fa_cannot_be_disabled(): void
    {
        $doctor = $this->userWithRole('DOCTOR', totp: true);

        $this->actingAs($doctor, 'sanctum')
            ->deleteJson('/api/v1/auth/2fa', ['password' => 'irrelevant-password', 'totp_code' => '123456'])
            ->assertUnprocessable();
        $this->assertTrue($doctor->fresh()->totp_enabled);
    }

    private function userWithRole(string $role, bool $totp): User
    {
        return User::factory()->create([
            'clinic_id' => $this->clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'totp_enabled' => $totp,
        ]);
    }
}
