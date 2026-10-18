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

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_limiting_blocks_excessive_requests(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'missing@example.test',
                'password' => 'not-a-password',
            ]);
            $this->assertNotEquals(429, $response->status());
        }
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'not-a-password',
        ]);
        $this->assertEquals(429, $response->status());
    }

    public function test_cors_headers_present(): void
    {
        $response = $this->getJson('/api/v1/patients');
        $response->assertHeader('Access-Control-Allow-Origin');
    }

    public function test_sql_injection_prevented(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Security Test Clinic',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'SECURITY_TEST',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'security@example.test',
            'status' => 'ACTIVE',
        ]);
        $user = User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', 'RECEPTIONIST')->value('id'),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/patients', [
            'first_name' => "'; DROP TABLE users; --",
            'last_name' => 'Test',
        ]);
        $response->assertCreated();
        $this->assertDatabaseHas('patients', ['first_name' => "'; DROP TABLE users; --"]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
