<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Employee;
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

/** The HR dashboard shows real figures for the HR manager (not a placeholder). */
class HrDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hr_manager_sees_real_staff_figures_instead_of_a_placeholder(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Clinique RH', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'RHD',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'rhd@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $hr = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'HR_MANAGER')->value('id'), 'status' => 'ACTIVE']);
        foreach (['Aïcha', 'Moussa', 'Hadiza'] as $index => $first) {
            Employee::create([
                'clinic_id' => $clinic->id, 'employee_number' => 'RHD-'.(100 + $index), 'first_name' => $first, 'last_name' => 'Test',
                'position' => 'Agent', 'department' => 'Administration', 'contract_type' => 'CDI', 'annual_leave_days' => 30,
                'base_salary' => 300000, 'hire_date' => '2024-01-01', 'status' => 'ACTIVE',
            ]);
        }

        $response = $this->actingAs($hr, 'sanctum')->getJson('/api/v1/dashboard')->assertOk();

        $widgets = collect($response->json('data.widgets') ?? $response->json('data'))->keyBy('key');
        $this->assertSame(3, $widgets['employees_active']['value']);
        $this->assertArrayHasKey('leaves_pending', $widgets->all());
        $this->assertArrayHasKey('on_call_today', $widgets->all());
        Carbon::setTestNow();
    }
}
