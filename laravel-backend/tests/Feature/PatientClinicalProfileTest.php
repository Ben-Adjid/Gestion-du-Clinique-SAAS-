<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientClinicalProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinical_staff_can_save_contacts_allergies_and_medical_history(): void
    {
        [$doctor, $patient] = $this->makeDoctorWithPatient();

        $this->actingAs($doctor, 'sanctum')
            ->putJson("/api/v1/patients/{$patient->id}/clinical-profile", [
                'contacts' => [[
                    'emergency_contact_name' => 'Awa Diallo',
                    'emergency_contact_phone' => '+221770000000',
                    'relationship' => 'Sœur',
                ]],
                'allergies' => [[
                    'allergen' => 'Pénicilline',
                    'severity' => 'HIGH',
                    'notes' => 'Réaction cutanée',
                ]],
                'medical_history' => [[
                    'condition' => 'Asthme',
                    'start_date' => '2020-01-01',
                    'end_date' => null,
                    'notes' => 'Suivi en cours',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.emergency_contacts.0.emergency_contact_name', 'Awa Diallo')
            ->assertJsonPath('data.allergies.0.severity', 'HIGH')
            ->assertJsonPath('data.medical_history.0.condition', 'Asthme');

        $this->assertDatabaseHas('patient_contacts', ['patient_id' => $patient->id]);
        $this->assertTrue($patient->allergies->contains(fn ($allergy) => $allergy->allergen === 'Pénicilline'));
        $this->assertTrue($patient->medicalHistory->contains(fn ($item) => $item->condition === 'Asthme'));
    }

    public function test_clinical_profile_rejects_invalid_dates_and_patient_edits(): void
    {
        [$doctor, $patient] = $this->makeDoctorWithPatient();
        $this->actingAs($doctor, 'sanctum')
            ->putJson("/api/v1/patients/{$patient->id}/clinical-profile", [
                'medical_history' => [[
                    'condition' => 'Condition invalide',
                    'start_date' => '2025-03-01',
                    'end_date' => '2025-02-01',
                ]],
            ])
            ->assertUnprocessable();

        $role = Role::create(['name' => 'PATIENT', 'display_name' => 'Patient']);
        $readProfile = Permission::where('name', 'read:patient_clinical_profile')->firstOrFail();
        $role->permissions()->attach($readProfile);
        $patientUser = User::factory()->create([
            'clinic_id' => $patient->clinic_id,
            'role_id' => $role->id,
        ]);
        $patient->update(['user_id' => $patientUser->id]);

        $this->actingAs($patientUser, 'sanctum')
            ->getJson("/api/v1/patients/{$patient->id}/clinical-profile")
            ->assertOk();

        $this->actingAs($patientUser, 'sanctum')
            ->putJson("/api/v1/patients/{$patient->id}/clinical-profile", [
                'allergies' => [['allergen' => 'Tentative de modification', 'severity' => 'HIGH']],
            ])
            ->assertForbidden();
    }

    private function makeDoctorWithPatient(): array
    {
        $clinic = Clinic::create([
            'name' => 'Clinique dossier médical',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'PAT_PROFILE',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'patient-profile@example.test',
            'status' => 'ACTIVE',
        ]);
        $role = Role::create(['name' => 'DOCTOR', 'display_name' => 'Médecin']);
        $permissions = collect(['read:patient', 'read:patient_clinical_profile', 'update:patient_clinical_profile'])
            ->map(fn (string $name) => Permission::create([
                'name' => $name,
                'resource' => 'patient',
                'action' => match ($name) {
                    'read:patient' => 'read',
                    'read:patient_clinical_profile' => 'read_clinical_profile',
                    default => 'update_clinical_profile',
                },
            ])->id);
        $role->permissions()->attach($permissions);
        $doctor = User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => $role->id,
        ]);
        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'patient_unique_id' => 'PAT_PROFILE-2026-00001',
            'first_name' => 'Aminata',
            'last_name' => 'Sow',
        ]);

        return [$doctor, $patient];
    }
}
