<?php

namespace Tests\Feature\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiClinicIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_only_see_their_clinic_data()
    {
        // Setup role
        $adminRole = Role::create(['name' => 'ADMIN_CLINIC', 'display_name' => 'Admin']);
        $readPatients = Permission::create([
            'name' => 'read:patient',
            'resource' => 'patient',
            'action' => 'read',
        ]);
        $adminRole->permissions()->attach($readPatients->id);

        // Create 2 clinics
        $clinic1 = Clinic::create([
            'name' => 'Clinic 1',
            'city' => 'Dakar',
            'country' => 'Senegal',
            'code' => 'C1',
            'admin_email' => 'admin1@test.com',
            'subscription_plan' => 'BASIC',
            'status' => 'ACTIVE',
        ]);

        $clinic2 = Clinic::create([
            'name' => 'Clinic 2',
            'city' => 'Thiès',
            'country' => 'Senegal',
            'code' => 'C2',
            'admin_email' => 'admin2@test.com',
            'subscription_plan' => 'PRO',
            'status' => 'ACTIVE',
        ]);

        // Create patients
        $patient1 = Patient::create([
            'clinic_id' => $clinic1->id,
            'patient_unique_id' => 'C1-2024-00001',
            'first_name' => 'John',
            'last_name' => 'Clinic1',
        ]);

        $patient2 = Patient::create([
            'clinic_id' => $clinic2->id,
            'patient_unique_id' => 'C2-2024-00001',
            'first_name' => 'Jane',
            'last_name' => 'Clinic2',
        ]);

        // Create users
        $user1 = User::create([
            'name' => 'User 1',
            'email' => 'user1@test.com',
            'password' => bcrypt('Password@12345'),
            'clinic_id' => $clinic1->id,
            'role_id' => $adminRole->id,
            'status' => 'ACTIVE',
        ]);

        $user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@test.com',
            'password' => bcrypt('Password@12345'),
            'clinic_id' => $clinic2->id,
            'role_id' => $adminRole->id,
            'status' => 'ACTIVE',
        ]);

        // Test user1 sees only clinic1 data
        $token1 = $user1->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer $token1")
            ->getJson('/api/v1/patients');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($patient1->id, $data[0]['id']);
        $this->assertEquals('Clinic1', $data[0]['last_name']);

        // Test user2 sees only clinic2 data
        Auth::forgetGuards();
        $user2Fresh = User::find($user2->id);
        $this->assertEquals($clinic2->id, $user2Fresh->clinic_id, 'User2 clinic_id should be ' . $clinic2->id);

        $token2 = $user2Fresh->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer $token2")
            ->getJson('/api/v1/patients');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data, 'User2 should see 1 patient, got: ' . json_encode($data));

        $this->assertEquals('Jane', $data[0]['first_name'], 'User2 should see Jane from clinic2, got ' . ($data[0]['first_name'] ?? 'null'));
        $this->assertEquals($patient2->id, $data[0]['id']);
        $this->assertEquals('Clinic2', $data[0]['last_name']);
    }

    public function test_user_cannot_access_other_clinic_patient()
    {
        // Setup
        $adminRole = Role::create(['name' => 'ADMIN_CLINIC', 'display_name' => 'Admin']);
        $readPatients = Permission::create([
            'name' => 'read:patient',
            'resource' => 'patient',
            'action' => 'read',
        ]);
        $adminRole->permissions()->attach($readPatients->id);

        $clinic1 = Clinic::create([
            'name' => 'Clinic 1',
            'city' => 'Dakar',
            'country' => 'Senegal',
            'code' => 'C1',
            'admin_email' => 'admin1@test.com',
            'subscription_plan' => 'BASIC',
            'status' => 'ACTIVE',
        ]);

        $clinic2 = Clinic::create([
            'name' => 'Clinic 2',
            'city' => 'Thiès',
            'country' => 'Senegal',
            'code' => 'C2',
            'admin_email' => 'admin2@test.com',
            'subscription_plan' => 'PRO',
            'status' => 'ACTIVE',
        ]);

        $patient = Patient::create([
            'clinic_id' => $clinic2->id,
            'patient_unique_id' => 'C2-2024-00001',
            'first_name' => 'Secret',
            'last_name' => 'Patient',
        ]);

        $user1 = User::create([
            'name' => 'User 1',
            'email' => 'user1@test.com',
            'password' => bcrypt('Password@12345'),
            'clinic_id' => $clinic1->id,
            'role_id' => $adminRole->id,
            'status' => 'ACTIVE',
        ]);

        // Try to access clinic2's patient
        $token = $user1->createToken('test')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/v1/patients/{$patient->id}");

        $response->assertStatus(404);
    }
}
