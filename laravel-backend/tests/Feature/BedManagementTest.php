<?php

namespace Tests\Feature;

use App\Models\Bed;
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
use Tests\TestCase;

class BedManagementTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $nurse;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Lits', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'LIT',
            'subscription_plan' => 'PRO', 'admin_email' => 'lit@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->nurse = $this->user('NURSE');
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'LIT-2026-000001', 'first_name' => 'Zara', 'last_name' => 'Test']);
    }

    public function test_a_nurse_admits_a_patient_into_a_free_bed_and_the_occupancy_follows(): void
    {
        $bed = $this->bed('M1-01');

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])
            ->assertOk()->assertJsonPath('data.status', 'OCCUPIED');

        $occupancy = $this->actingAs($this->nurse, 'sanctum')->getJson('/api/v1/beds')->assertOk()->json('data.occupancy');
        $this->assertSame(1, $occupancy['occupied']);
        $this->assertEquals(100, $occupancy["rate"]);
    }

    public function test_a_busy_bed_or_a_patient_already_admitted_is_refused(): void
    {
        $first = $this->bed('M1-01');
        $second = $this->bed('M1-02');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$first->id}/assign", ['patient_id' => $this->patient->id])->assertOk();

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$first->id}/assign", ['patient_id' => $this->patient->id])->assertUnprocessable();
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$second->id}/assign", ['patient_id' => $this->patient->id])
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
    }

    public function test_discharge_frees_the_bed_and_a_bed_with_a_patient_cannot_go_out_of_service(): void
    {
        $bed = $this->bed('M1-01');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertOk();

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/maintenance", ['out_of_service' => true])->assertUnprocessable();
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/release")->assertOk()->assertJsonPath('data.status', 'CLEANING');
        $this->assertNull($bed->fresh()->patient_id);
        // A bed in disinfection cannot take a patient until it is ready.
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertUnprocessable();
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/ready")->assertOk()->assertJsonPath('data.status', 'AVAILABLE');
    }

    public function test_doctors_nurses_and_administration_install_patients_and_others_cannot(): void
    {
        $bed = $this->bed('M1-01');

        // Decided: the doctor, the nurse and the clinic administrator may install a patient in a bed.
        $this->actingAs($this->user('DOCTOR'), 'sanctum')->getJson('/api/v1/beds')->assertOk();
        $this->actingAs($this->user('DOCTOR'), 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertOk();
        $this->actingAs($this->user('CASHIER'), 'sanctum')->getJson('/api/v1/beds')->assertForbidden();
        $this->actingAs($this->user('RECEPTIONIST'), 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertForbidden();
    }

    public function test_a_bed_of_another_clinic_is_not_found(): void
    {
        $bed = $this->bed('M1-01');
        $other = Clinic::create([
            'name' => 'Autre lits', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AUL',
            'subscription_plan' => 'PRO', 'admin_email' => 'aul@example.test', 'status' => 'ACTIVE',
        ]);
        $outsider = User::factory()->create(['clinic_id' => $other->id, 'role_id' => Role::where('name', 'NURSE')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertNotFound();
    }

    private function bed(string $code): Bed
    {
        return Bed::query()->create(['clinic_id' => $this->clinic->id, 'code' => $code, 'ward' => 'Médecine interne', 'status' => 'AVAILABLE']);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'clinic_id' => $this->clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }
}
