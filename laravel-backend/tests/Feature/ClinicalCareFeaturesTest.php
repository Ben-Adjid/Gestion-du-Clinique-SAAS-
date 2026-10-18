<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\DrugInteraction;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalCareFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $doctor;
    private User $nurse;
    private Patient $patient;
    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->clinic = $this->makeClinic('CARE');
        $this->doctor = $this->userWithRole('DOCTOR');
        $this->nurse = $this->userWithRole('NURSE');
        $this->patient = $this->makePatient($this->clinic);
        $this->consultation = Consultation::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'consultation_datetime' => now(),
            'status' => 'IN_PROGRESS',
        ]);
    }

    public function test_doctor_assigns_a_care_task_and_nurse_completes_it(): void
    {
        $taskId = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/v1/consultations/{$this->consultation->id}/tasks", [
                'assigned_to' => $this->nurse->id,
                'task_description' => 'Injection IM ceftriaxone',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->nurse, 'sanctum')->getJson('/api/v1/care-tasks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $taskId);

        $this->actingAs($this->nurse, 'sanctum')->patchJson("/api/v1/care-tasks/{$taskId}", ['status' => 'COMPLETED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.completed_by', $this->nurse->id);

        $this->actingAs($this->nurse, 'sanctum')->patchJson("/api/v1/care-tasks/{$taskId}", ['status' => 'IN_PROGRESS'])
            ->assertUnprocessable();
    }

    public function test_only_the_assigned_nurse_can_update_a_task(): void
    {
        $taskId = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/v1/consultations/{$this->consultation->id}/tasks", [
                'assigned_to' => $this->nurse->id,
                'task_description' => 'Pansement',
            ])->json('data.id');

        $this->actingAs($this->userWithRole('NURSE'), 'sanctum')
            ->patchJson("/api/v1/care-tasks/{$taskId}", ['status' => 'COMPLETED'])
            ->assertForbidden();
    }

    public function test_tasks_can_only_be_assigned_to_nurses_of_the_clinic(): void
    {
        $outsider = User::factory()->create([
            'clinic_id' => $this->makeClinic('OTHER')->id,
            'role_id' => Role::where('name', 'NURSE')->value('id'),
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/v1/consultations/{$this->consultation->id}/tasks", [
                'assigned_to' => $outsider->id,
                'task_description' => 'Prise de sang',
            ])
            ->assertUnprocessable();
    }

    public function test_nurse_cannot_create_care_tasks(): void
    {
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson("/api/v1/consultations/{$this->consultation->id}/tasks", [
                'assigned_to' => $this->nurse->id,
                'task_description' => 'Auto-assignée',
            ])
            ->assertForbidden();
    }

    public function test_nurse_records_vital_signs_with_alerts(): void
    {
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/vitals", [
                'systolic_bp' => 125,
                'diastolic_bp' => 80,
                'temperature' => 39.2,
                'spo2' => 97,
            ])
            ->assertCreated()
            ->assertJsonPath('data.alerts', ['Fièvre']);
    }

    public function test_vital_signs_are_validated(): void
    {
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/vitals", [])
            ->assertUnprocessable();
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/vitals", ['systolic_bp' => 80, 'diastolic_bp' => 90])
            ->assertUnprocessable();
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/vitals", ['temperature' => 60])
            ->assertUnprocessable();
    }

    public function test_cashier_cannot_record_vital_signs(): void
    {
        $this->actingAs($this->userWithRole('CASHIER'), 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/vitals", ['heart_rate' => 80])
            ->assertForbidden();
    }

    public function test_vitals_of_another_clinic_patient_are_not_exposed(): void
    {
        $otherPatient = $this->makePatient($this->makeClinic('FOREIGN'));

        $this->actingAs($this->nurse, 'sanctum')
            ->getJson("/api/v1/patients/{$otherPatient->id}/vitals")
            ->assertNotFound();
    }

    public function test_timeline_lists_medical_events_most_recent_first(): void
    {
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/vitals", ['heart_rate' => 72])
            ->assertCreated();

        $events = $this->actingAs($this->doctor, 'sanctum')
            ->getJson("/api/v1/patients/{$this->patient->id}/timeline")
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing(['consultation', 'vitals'], array_column($events, 'type'));
    }

    public function test_timeline_requires_medical_record_permission(): void
    {
        $this->actingAs($this->userWithRole('RECEPTIONIST'), 'sanctum')
            ->getJson("/api/v1/patients/{$this->patient->id}/timeline")
            ->assertForbidden();
    }

    public function test_severe_drug_interaction_blocks_prescription_until_acknowledged(): void
    {
        [$warfarin, $aspirin] = [$this->makeMedication('Warfarine'), $this->makeMedication('Aspirine')];
        DrugInteraction::create([
            'drug_id_1' => min($warfarin->id, $aspirin->id),
            'drug_id_2' => max($warfarin->id, $aspirin->id),
            'severity' => 'HIGH',
            'description' => 'Risque hémorragique majeur',
        ]);
        $payload = [
            'consultation_id' => $this->consultation->id,
            'items' => [$this->item($warfarin), $this->item($aspirin)],
        ];

        $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'DRUG_INTERACTION')
            ->assertJsonPath('interactions.0.severity', 'HIGH');

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/v1/prescriptions', [...$payload, 'acknowledge_interactions' => true])
            ->assertCreated();
    }

    public function test_interaction_with_current_treatment_is_detected(): void
    {
        [$warfarin, $aspirin] = [$this->makeMedication('Warfarine'), $this->makeMedication('Aspirine')];
        DrugInteraction::create([
            'drug_id_1' => min($warfarin->id, $aspirin->id),
            'drug_id_2' => max($warfarin->id, $aspirin->id),
            'severity' => 'MEDIUM',
            'description' => 'Surveillance INR',
        ]);
        $current = Prescription::create([
            'clinic_id' => $this->clinic->id,
            'consultation_id' => $this->consultation->id,
            'doctor_id' => $this->doctor->id,
            'prescribed_at' => now()->subDays(3),
            'status' => 'SIGNED',
        ]);
        $current->items()->create($this->item($warfarin));

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/v1/prescriptions/check-interactions', [
                'consultation_id' => $this->consultation->id,
                'medication_ids' => [$aspirin->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.severity', 'MEDIUM')
            ->assertJsonPath('data.0.with_current_treatment', true);
    }

    public function test_only_pharmacy_can_declare_interactions(): void
    {
        [$first, $second] = [$this->makeMedication('A'), $this->makeMedication('B')];
        $payload = ['drug_id_1' => $first->id, 'drug_id_2' => $second->id, 'severity' => 'LOW', 'description' => 'Test'];

        $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/drug-interactions', $payload)->assertForbidden();
        $this->actingAs($this->userWithRole('PHARMACIST'), 'sanctum')->postJson('/api/v1/drug-interactions', $payload)->assertCreated();
        $this->actingAs($this->userWithRole('PHARMACIST'), 'sanctum')
            ->postJson('/api/v1/drug-interactions', [...$payload, 'drug_id_1' => $second->id, 'drug_id_2' => $first->id])
            ->assertUnprocessable();
    }

    private function item(Medication $medication): array
    {
        return [
            'medication_id' => $medication->id,
            'dosage_prescribed' => '1 comprimé',
            'frequency' => '2 fois par jour',
            'duration_days' => 5,
            'quantity' => 10,
        ];
    }

    private function makeMedication(string $name): Medication
    {
        return Medication::create([
            'clinic_id' => $this->clinic->id,
            'name' => $name,
            'dosage' => '100 mg',
            'form' => 'Comprimé',
            'price' => 1000,
        ]);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'clinic_id' => $this->clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    private function makeClinic(string $code): Clinic
    {
        return Clinic::create([
            'name' => "Clinic {$code}", 'city' => 'Dakar', 'country' => 'Sénégal', 'code' => $code,
            'subscription_plan' => 'BASIC', 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
    }

    private function makePatient(Clinic $clinic): Patient
    {
        return Patient::create([
            'clinic_id' => $clinic->id,
            'patient_unique_id' => strtoupper(Str::random(12)),
            'first_name' => 'Awa',
            'last_name' => 'Diop',
        ]);
    }
}
