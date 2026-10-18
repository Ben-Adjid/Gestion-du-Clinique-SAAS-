<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Permission;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_stats_endpoint(): void
    {
        $response = $this->actingAs($this->makePerformanceAdmin(), 'sanctum')
            ->getJson('/api/v1/performance/cache-stats');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'cache' => ['enabled', 'driver', 'ttl'],
            'pagination' => ['default_per_page', 'max_per_page'],
            'stats' => ['cached_queries', 'cache_hits', 'cache_misses'],
        ]);
    }

    public function test_search_patients_with_caching(): void
    {
        $user = $this->makePerformanceAdmin();
        $foreignClinic = Clinic::create([
            'name' => 'Autre clinique performance',
            'city' => 'Thiès',
            'country' => 'Sénégal',
            'code' => 'OTHER_PERFORMANCE',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'other-performance@example.test',
            'status' => 'ACTIVE',
        ]);
        Patient::create([
            'clinic_id' => $user->clinic_id,
            'patient_unique_id' => 'PERFORMANCE-LOCAL-001',
            'first_name' => 'Secret Local',
            'last_name' => 'Patient',
        ]);
        Patient::create([
            'clinic_id' => $foreignClinic->id,
            'patient_unique_id' => 'PERFORMANCE-FOREIGN-001',
            'first_name' => 'Secret Foreign',
            'last_name' => 'Patient',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/performance/search-patients?q=Secret');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient_unique_id', 'PERFORMANCE-LOCAL-001');
    }

    public function test_lazy_load_appointments(): void
    {
        $response = $this->actingAs($this->makePerformanceAdmin(), 'sanctum')
            ->getJson('/api/v1/performance/appointments?page=1&per_page=15');
        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
    }

    private function makePerformanceAdmin(): User
    {
        $clinic = Clinic::create([
            'name' => 'Clinique Performance',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'PERFORMANCE',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'performance@example.test',
            'status' => 'ACTIVE',
        ]);
        $role = Role::create(['name' => 'ADMIN_CLINIC', 'display_name' => 'Administrateur']);
        $permissions = collect(['view:reports', 'read:patient', 'read:appointment'])
            ->map(fn (string $name) => Permission::create([
                'name' => $name,
                'resource' => 'test',
                'action' => 'read',
            ])->id);
        $role->permissions()->attach($permissions);

        return User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => $role->id,
        ]);
    }
}
