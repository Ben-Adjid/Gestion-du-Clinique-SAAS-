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
use Tests\TestCase;

/** Who may prescribe analyses and book appointments: the doctor and reception, never nursing or laboratory staff. */
class ClinicalRightsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Clinique Droits', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'DRT',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'drt@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->admin = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE']);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'clinic_id' => $this->admin->clinic_id, 'role_id' => Role::where('name', $role)->value('id'), 'status' => 'ACTIVE',
        ]);
    }

    public function test_a_nurse_cannot_request_laboratory_analyses(): void
    {
        $this->actingAs($this->userWithRole('NURSE'), 'sanctum')
            ->postJson('/api/v1/lab-requests', ['consultation_id' => 1, 'exam_ids' => [1]])
            ->assertForbidden()
            ->assertJsonPath('message', 'Seul le médecin peut demander des analyses.');
    }

    public function test_a_laboratory_technician_cannot_request_laboratory_analyses(): void
    {
        $this->actingAs($this->userWithRole('LABORATORY_TECHNICIAN'), 'sanctum')
            ->postJson('/api/v1/lab-requests', ['consultation_id' => 1, 'exam_ids' => [1]])
            ->assertForbidden();
    }

    public function test_a_doctor_still_reaches_the_analysis_request_validation(): void
    {
        $this->actingAs($this->userWithRole('DOCTOR'), 'sanctum')
            ->postJson('/api/v1/lab-requests', [])
            ->assertUnprocessable();
    }

    public function test_an_expected_error_returns_a_french_message_without_class_name_or_trace(): void
    {
        $doctor = $this->userWithRole('DOCTOR');
        $patient = \App\Models\Patient::create(['clinic_id' => $doctor->clinic_id, 'patient_unique_id' => 'DRT-2026-000001', 'first_name' => 'Ali', 'last_name' => 'Test', 'date_of_birth' => '1980-01-01']);
        $consultation = \App\Models\Consultation::create([
            'clinic_id' => $doctor->clinic_id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'consultation_datetime' => now(), 'status' => 'SIGNED',
        ]);
        $prescription = \App\Models\Prescription::create([
            'clinic_id' => $doctor->clinic_id, 'consultation_id' => $consultation->id, 'doctor_id' => $doctor->id,
            'prescribed_at' => now(), 'status' => 'DISPENSED',
        ]);

        $response = $this->actingAs($this->userWithRole('PHARMACIST'), 'sanctum')
            ->postJson("/api/v1/prescriptions/{$prescription->id}/dispense")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Seule une ordonnance signée peut être délivrée, et une seule fois.');

        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertArrayNotHasKey('trace', $response->json());
    }

    public function test_a_nurse_cannot_book_an_appointment_but_reception_can(): void
    {
        $this->actingAs($this->userWithRole('NURSE'), 'sanctum')
            ->postJson('/api/v1/appointments', [])
            ->assertForbidden()
            ->assertJsonPath('message', 'Les rendez-vous sont pris par l’accueil ou le médecin, pas par le personnel soignant.');

        $this->actingAs($this->userWithRole('RECEPTIONIST'), 'sanctum')
            ->postJson('/api/v1/appointments', [])
            ->assertUnprocessable();
    }
}
