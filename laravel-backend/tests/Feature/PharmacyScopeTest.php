<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Prescription;
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

/** The pharmacist works on active prescriptions only, never on the clinical history. */
class PharmacyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $pharmacist;
    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 09:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Pharmacie', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'PHA',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'pha@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $doctor = $this->user('DOCTOR');
        $this->pharmacist = $this->user('PHARMACIST');
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'PHA-2026-000001', 'first_name' => 'Yacine', 'last_name' => 'Test']);
        $this->consultation = Consultation::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'consultation_datetime' => now(), 'status' => 'SIGNED',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pharmacist_lists_only_signed_recent_prescriptions(): void
    {
        $active = $this->prescription('SIGNED', now()->subDays(2));
        $this->prescription('DISPENSED', now()->subDays(1));
        $this->prescription('SIGNED', now()->subDays(45));

        $ids = collect($this->actingAs($this->pharmacist, 'sanctum')->getJson('/api/v1/prescriptions')->json('data'))->pluck('id');

        $this->assertSame([$active->id], $ids->all());
    }

    public function test_pharmacist_cannot_open_history_or_the_clinical_note(): void
    {
        $dispensed = $this->prescription('DISPENSED', now()->subDay());
        $old = $this->prescription('SIGNED', now()->subDays(45));
        $active = $this->prescription('SIGNED', now()->subDay());

        $this->actingAs($this->pharmacist, 'sanctum')->getJson("/api/v1/prescriptions/{$dispensed->id}")->assertNotFound();
        $this->actingAs($this->pharmacist, 'sanctum')->getJson("/api/v1/prescriptions/{$old->id}")->assertNotFound();
        $this->actingAs($this->pharmacist, 'sanctum')->getJson("/api/v1/prescriptions/{$active->id}")->assertOk();
        // The consultation itself (diagnosis, notes) stays closed to the pharmacy.
        $this->actingAs($this->pharmacist, 'sanctum')->getJson("/api/v1/consultations/{$this->consultation->id}")->assertForbidden();
    }

    private function prescription(string $status, Carbon $when): Prescription
    {
        return Prescription::create([
            'clinic_id' => $this->clinic->id, 'consultation_id' => $this->consultation->id,
            'doctor_id' => $this->consultation->doctor_id, 'prescribed_at' => $when, 'status' => $status,
        ]);
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
