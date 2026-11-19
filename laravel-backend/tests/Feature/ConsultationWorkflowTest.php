<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_can_create_complete_and_sign_a_clinic_consultation(): void
    {
        [$clinic, $doctor, $patient] = $this->makeDoctor();
        $this->actingAs($doctor, 'sanctum');

        $created = $this->postJson('/api/v1/consultations', [
            'patient_id' => $patient->id,
            'consultation_datetime' => now()->toIso8601String(),
            'notes' => 'Patient symptom summary.',
            'diagnosis' => 'Clinical assessment.',
            'sections' => [
                ['section_number' => 2, 'title' => 'Antécédents utiles', 'content' => 'Antécédent documenté.'],
                ['section_number' => 3, 'title' => 'Examen clinique', 'content' => 'Examen initial.'],
            ],
        ]);
        $created->assertCreated()
            ->assertJsonPath('data.clinic_id', $clinic->id)
            ->assertJsonPath('data.doctor_id', $doctor->id)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.sections.0.content', 'Patient symptom summary.')
            ->assertJsonPath('data.sections.1.title', 'Antécédents utiles')
            ->assertJsonPath('data.diagnostics.0.cim10_description', 'Clinical assessment.');

        $consultationId = $created->json('data.id');
        $this->patchJson("/api/v1/consultations/{$consultationId}", [
            'status' => 'COMPLETED',
            'sections' => [
                ['section_number' => 2, 'title' => 'Antécédents utiles', 'content' => 'Antécédent mis à jour.'],
                ['section_number' => 3, 'title' => 'Examen clinique', 'content' => 'Examen initial.'],
            ],
        ])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        // Clinical text is encrypted at rest: the check goes through the model, which decrypts.
        $this->assertSame('Antécédent mis à jour.', \App\Models\ConsultationSection::query()
            ->where('consultation_id', $consultationId)->where('section_number', 2)->value('content'));
        $this->assertDatabaseMissing('consultation_sections', [
            'consultation_id' => $consultationId,
            'section_number' => 2,
            'content' => 'Antécédent mis à jour.',
        ]);
        $this->postJson("/api/v1/consultations/{$consultationId}/sign")
            ->assertOk()
            ->assertJsonPath('data.status', 'SIGNED')
            ->assertJsonPath('data.digital_signatures.0.user_id', $doctor->id);

        $this->patchJson("/api/v1/consultations/{$consultationId}", [
            'notes' => 'Attempted alteration.',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('consultations', [
            'id' => $consultationId,
            'clinic_id' => $clinic->id,
            'status' => 'SIGNED',
        ]);
        $this->assertDatabaseHas('digital_signatures', [
            'consultation_id' => $consultationId,
            'user_id' => $doctor->id,
        ]);
    }

    public function test_consultation_creation_rejects_a_patient_from_another_clinic(): void
    {
        [$clinic, $doctor] = $this->makeDoctor();
        $foreignClinic = Clinic::create([
            'name' => 'Cabinet externe',
            'city' => 'Thiès',
            'country' => 'Sénégal',
            'code' => 'FOREIGN_CONSULT',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'foreign-consult@example.test',
            'status' => 'ACTIVE',
        ]);
        $foreignPatient = Patient::create([
            'clinic_id' => $foreignClinic->id,
            'patient_unique_id' => 'FOREIGN-2026-00001',
            'first_name' => 'Patient',
            'last_name' => 'Externe',
        ]);

        $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/v1/consultations', [
                'patient_id' => $foreignPatient->id,
                'consultation_datetime' => now()->toIso8601String(),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('consultations', ['clinic_id' => $clinic->id]);
    }

    private function makeDoctor(): array
    {
        $clinic = Clinic::create([
            'name' => 'Clinique de consultations',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'CONSULTATIONS',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'consultations@example.test',
            'status' => 'ACTIVE',
        ]);
        $role = Role::create(['name' => 'DOCTOR', 'display_name' => 'Médecin']);
        $permissions = collect(['create:consultation', 'read:consultation', 'update:consultation', 'sign:consultation'])
            ->map(fn (string $name) => Permission::create([
                'name' => $name,
                'resource' => 'consultation',
                'action' => explode(':', $name, 2)[0],
            ])->id);
        $role->permissions()->attach($permissions);
        $doctor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => $role->id,
        ]);
        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'patient_unique_id' => 'CONSULTATIONS-2026-00001',
            'first_name' => 'Mamadou',
            'last_name' => 'Diallo',
        ]);

        return [$clinic, $doctor, $patient];
    }
}
