<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Permission;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinic_admin_can_create_an_allowed_clinic_user(): void
    {
        [$clinic, $admin] = $this->makeClinicAdmin();
        $doctor = Role::create([
            'name' => 'DOCTOR',
            'display_name' => 'Médecin',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Dr Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '+221770000001',
            'password' => 'SecurePass@1234',
            'role_id' => $doctor->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.clinic_id', $clinic->id)
            ->assertJsonPath('data.role.name', 'DOCTOR');
        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.test',
            'clinic_id' => $clinic->id,
            'role_id' => $doctor->id,
        ]);
    }

    public function test_clinic_admin_cannot_assign_super_admin_role(): void
    {
        [, $admin] = $this->makeClinicAdmin();
        $superAdmin = Role::create([
            'name' => 'SUPER_ADMIN',
            'display_name' => 'Super-administrateur',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Elevated account',
            'email' => 'elevated@example.test',
            'password' => 'SecurePass@1234',
            'role_id' => $superAdmin->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'elevated@example.test']);
    }

    public function test_clinic_admin_can_create_patient_account_for_unlinked_record(): void
    {
        [$clinic, $admin] = $this->makeClinicAdmin();
        $admin->role->permissions()->attach(Permission::create([
            'name' => 'read:patient',
            'resource' => 'patient',
            'action' => 'read',
        ])->id);
        $patientRole = Role::create([
            'name' => 'PATIENT',
            'display_name' => 'Patient',
        ]);
        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'patient_unique_id' => 'TEST-2026-00001',
            'first_name' => 'Aminata',
            'last_name' => 'Test',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Aminata Test',
            'email' => 'aminata@example.test',
            'password' => 'SecurePass@1234',
            'role_id' => $patientRole->id,
            'patient_id' => $patient->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role.name', 'PATIENT')
            ->assertJsonPath('data.patient.id', $patient->id);
        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'user_id' => $response->json('data.id'),
        ]);
    }

    private function makeClinicAdmin(): array
    {
        $clinic = Clinic::create([
            'name' => 'Clinique de test',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'TEST_CLINIC',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'admin@test.example',
            'status' => 'ACTIVE',
        ]);
        $role = Role::create([
            'name' => 'ADMIN_CLINIC',
            'display_name' => 'Administrateur clinique',
        ]);
        $permission = Permission::create([
            'name' => 'manage:users',
            'resource' => 'admin',
            'action' => 'manage_users',
        ]);
        $role->permissions()->attach($permission->id);
        $admin = User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => $role->id,
        ]);

        return [$clinic, $admin];
    }
}
