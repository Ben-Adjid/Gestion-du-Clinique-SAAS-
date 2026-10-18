<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Clinic;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_workflow(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $receptionistRole = Role::where('name', 'RECEPTIONIST')->firstOrFail();

        // 1. Create clinic
        $clinic = Clinic::create([
            'name' => 'Test Clinic',
            'city' => 'Dakar',
            'country' => 'Senegal',
            'code' => 'TEST_CLINIC',
            'subscription_plan' => 'PRO',
            'admin_email' => 'admin@test.example',
            'status' => 'ACTIVE',
        ]);

        // 2. Create user
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '221771234567',
            'password' => bcrypt('Password@12345'),
            'clinic_id' => $clinic->id,
            'role_id' => $receptionistRole->id,
            'status' => 'ACTIVE',
        ]);

        // 3. Test login
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'Password@12345',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
            'user' => ['id', 'email', 'clinic_id'],
        ]);

        $token = $response->json('access_token');

        // 4. Test create patient
        $patientResponse = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/patients', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'phone' => '221771111111',
            ]);

        $patientResponse->assertStatus(201);
        $patientId = $patientResponse->json('data.id');

        // 5. Test get patients
        $patientsResponse = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/patients');

        $patientsResponse->assertStatus(200);
        $patientsResponse->assertJsonStructure(['data']);

        // 6. Test logout
        $logoutResponse = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);
    }

    public function test_api_endpoints_all_working(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $endpoints = [
            ['GET', '/api/v1/patients'],
            ['GET', '/api/v1/appointments'],
            ['GET', '/api/v1/consultations'],
            ['GET', '/api/v1/prescriptions'],
            ['GET', '/api/v1/invoices'],
        ];

        foreach ($endpoints as [$method, $path]) {
            $response = $this->withHeader('Authorization', "Bearer $token")
                ->{strtolower($method)}($path);

            $this->assertNotEquals(500, $response->status(), "Endpoint $method $path returned 500");
        }
    }
}
