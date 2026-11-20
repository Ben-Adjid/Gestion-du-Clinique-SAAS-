<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** HR reads the doctors' planning and on-call duty, and never opens a medical record. */
class HrPlanningAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hr_reads_the_doctors_planning_but_not_medical_records(): void
    {
        $clinic = Clinic::create([
            'name' => 'Clinique RH', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'RHC',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'rhc@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $hr = $this->user($clinic, 'HR_MANAGER');
        $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_unique_id' => 'RHC-2026-000001', 'first_name' => 'Ali', 'last_name' => 'Test', 'date_of_birth' => '1970-01-01']);

        $this->actingAs($hr, 'sanctum')->getJson('/api/v1/planning/board')->assertOk();
        $this->actingAs($hr, 'sanctum')->getJson("/api/v1/patients/{$patient->id}")->assertForbidden();
        $this->actingAs($hr, 'sanctum')->getJson('/api/v1/consultations')->assertForbidden();
    }

    private function user(Clinic $clinic, string $role): User
    {
        return User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }
}
