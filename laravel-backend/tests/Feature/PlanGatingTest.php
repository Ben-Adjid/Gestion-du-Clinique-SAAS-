<?php

namespace Tests\Feature;

use App\Models\Clinic;
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

/** The offer: what each plan opens (BASIC / PRO / ENTERPRISE), and the French upsell when a feature is out of plan. */
class PlanGatingTest extends TestCase
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

    /** A clinic on the given plan, with an administrator and a doctor. */
    private function clinic(string $plan, string $code): array
    {
        $clinic = Clinic::create([
            'name' => "Clinique {$plan}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => $plan, 'admin_email' => "{$code}@example.test", 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', $plan)->firstOrFail(), 'MONTHLY', false);
        $admin = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE']);
        $doctor = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);
        $doctor->doctorProfile()->create(['clinic_id' => $clinic->id, 'specialty' => 'Chirurgie générale']);

        return [$clinic, $admin, $doctor];
    }

    public function test_basic_refuses_beds_with_a_french_upsell_towards_pro(): void
    {
        [, $admin] = $this->clinic('BASIC', 'BSC');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/beds')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED')
            ->assertJsonPath('upgrade_to', 'PRO')
            ->assertJsonPath('feature', 'beds')
            ->assertJsonPath('message', 'Cette fonctionnalité n’est pas incluse dans la formule actuelle de votre clinique. Passez à la formule PRO pour l’activer.');
    }

    public function test_pro_opens_beds_and_the_simple_operating_theatre_but_not_the_specialty_circuit(): void
    {
        [, $admin, $doctor] = $this->clinic('PRO', 'PRO');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/beds')->assertOk();
        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/surgeries')->assertOk();
        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/indications')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED')
            ->assertJsonPath('upgrade_to', 'ENTERPRISE');
    }

    public function test_pro_plans_an_operation_without_indication_and_skips_the_circuit_gates(): void
    {
        [, , $doctor] = $this->clinic('PRO', 'PRO2');
        $patient = \App\Models\Patient::create(['clinic_id' => $doctor->clinic_id, 'patient_unique_id' => 'PRO-2026-000001', 'first_name' => 'Hadiza', 'last_name' => 'Test', 'date_of_birth' => '1985-04-04']);

        $this->actingAs($doctor, 'sanctum')->postJson('/api/v1/surgeries', [
            'patient_id' => $patient->id,
            'operation_type' => 'Appendicectomie',
            'scheduled_at' => '2026-11-25 08:00',
            'surgeon_id' => $doctor->id,
        ])->assertCreated();

        // No consent or checklist is asked on the simple theatre: the circuit is Enterprise only.
        $surgery = \App\Models\Surgery::query()->latest('id')->firstOrFail();
        $this->actingAs($doctor, 'sanctum')->patchJson("/api/v1/surgeries/{$surgery->id}", ["status" => "IN_PROGRESS"])->assertOk();
    }

    public function test_pro_refuses_the_consent_form_with_the_upsell(): void
    {
        [, , $doctor] = $this->clinic('PRO', 'PRO3');
        $patient = \App\Models\Patient::create(['clinic_id' => $doctor->clinic_id, 'patient_unique_id' => 'PRO-2026-000002', 'first_name' => 'Ali', 'last_name' => 'Test', 'date_of_birth' => '1982-06-06']);
        $surgery = \App\Models\Surgery::create([
            'clinic_id' => $doctor->clinic_id, 'patient_id' => $patient->id, 'operation_type' => 'Hernie', 'scheduled_at' => '2026-11-26 08:00:00',
            'status' => 'PLANNED', 'surgeon_id' => $doctor->id, 'created_by' => $doctor->id,
        ]);

        $this->actingAs($doctor, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/consent", [])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');
    }

    public function test_enterprise_opens_the_circuit_and_requires_an_indication_to_plan(): void
    {
        [, , $doctor] = $this->clinic('ENTERPRISE', 'ENT');
        $patient = \App\Models\Patient::create(['clinic_id' => $doctor->clinic_id, 'patient_unique_id' => 'ENT-2026-000001', 'first_name' => 'Fatouma', 'last_name' => 'Test', 'date_of_birth' => '1979-07-07']);

        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/indications')->assertOk();
        $this->actingAs($doctor, 'sanctum')->postJson('/api/v1/surgeries', [
            'patient_id' => $patient->id,
            'operation_type' => 'Appendicectomie',
            'scheduled_at' => '2026-11-25 08:00',
            'surgeon_id' => $doctor->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['indication_id']);
    }
}
