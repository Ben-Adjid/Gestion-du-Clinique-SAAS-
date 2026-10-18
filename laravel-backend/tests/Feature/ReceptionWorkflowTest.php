<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Models\VitalSign;
use Database\Seeders\Cim10Seeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceptionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $receptionist;
    private User $doctor;
    private User $otherDoctor;
    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, Cim10Seeder::class]);
        $this->clinic = $this->makeClinic('RECEP');
        $this->receptionist = $this->user('RECEPTIONIST', $this->clinic);
        $this->doctor = $this->user('DOCTOR', $this->clinic);
        $this->otherDoctor = $this->user('DOCTOR', $this->clinic, 'other');
        $this->nurse = $this->user('NURSE', $this->clinic);
    }

    public function test_full_registration_saves_identity_and_clinical_profile_in_one_go(): void
    {
        $response = $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/v1/patients', [
            'first_name' => 'Halimé', 'last_name' => 'Mahamat', 'gender' => 'F', 'date_of_birth' => '1990-04-12',
            'national_id' => 'TD-123456', 'marital_status' => 'MARRIED', 'profession' => 'Enseignante',
            'phone' => '+235 66 12 34 56', 'address' => 'Quartier Moursal', 'city' => 'N’Djamena', 'blood_group' => 'O+',
            'contacts' => [['emergency_contact_name' => 'Abakar Mahamat', 'emergency_contact_phone' => '+235 99 00 11 22', 'relationship' => 'Époux']],
            'allergies' => [['allergen' => 'Pénicilline', 'severity' => 'HIGH']],
            'medical_history' => [['condition' => 'Asthme']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.national_id', 'TD-123456')
            ->assertJsonPath('data.blood_group', 'O+')
            ->assertJsonPath('data.patient_unique_id', 'RECEP-'.now()->year.'-000001')
            ->assertJsonPath('data.clinical_profile_visible', false);
        $patientId = $response->json('data.id');
        $this->assertDatabaseHas('patient_contacts', ['patient_id' => $patientId, 'emergency_contact_name' => 'Abakar Mahamat']);
        $this->assertDatabaseHas('patient_allergies', ['patient_id' => $patientId, 'allergen' => 'Pénicilline', 'severity' => 'HIGH']);
        $this->assertDatabaseHas('patient_medical_history', ['patient_id' => $patientId, 'condition' => 'Asthme']);
    }

    public function test_possible_duplicate_is_blocked_until_confirmed(): void
    {
        $this->patient('Moussa', 'Abdelkerim', '1985-01-01', '+235 66 00 00 01');
        $payload = ['first_name' => 'Mousa', 'last_name' => 'Abdelkerim', 'date_of_birth' => '1985-01-01', 'gender' => 'M'];

        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/v1/patients', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'POSSIBLE_DUPLICATE')
            ->assertJsonPath('candidates.0.last_name', 'Abdelkerim');
        $this->assertSame(1, Patient::count());

        $this->getJson('/api/v1/patients/duplicates?'.http_build_query($payload))->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/patients', [...$payload, 'confirm_not_duplicate' => true])->assertCreated();
        $this->assertSame(2, Patient::count());
        $this->assertDatabaseHas('duplicate_alerts', ['status' => 'PENDING']);
    }

    public function test_same_phone_number_with_close_name_is_flagged(): void
    {
        $this->patient('Fatimé', 'Hassan', null, '+235 66 77 88 99');

        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/v1/patients', [
            'first_name' => 'Fatime', 'last_name' => 'Hasan', 'phone' => '66778899',
        ])->assertStatus(409);
    }

    public function test_patient_search_is_paginated_and_matches_name_phone_and_number(): void
    {
        foreach (range(1, 30) as $index) {
            $this->patient("Prénom{$index}", "Famille{$index}", null, '+235 60 00 00 '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }
        $target = $this->patient('Zara', 'Idriss', null, '+235 91 23 45 67');

        $this->actingAs($this->receptionist, 'sanctum');
        $this->getJson('/api/v1/patients?per_page=10')->assertOk()->assertJsonPath('total', 31)->assertJsonCount(10, 'data');
        $this->getJson('/api/v1/patients?search=zara idr')->assertOk()->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/v1/patients?search=91234567')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/v1/patients?search='.$target->patient_unique_id)->assertOk()->assertJsonPath('data.0.id', $target->id);
    }

    public function test_photo_upload_is_private_to_the_clinic(): void
    {
        Storage::fake('local');
        $patient = $this->patient('Achta', 'Djibrine');
        $this->actingAs($this->receptionist, 'sanctum')
            ->post("/api/v1/patients/{$patient->id}/photo", ['photo' => $this->pngUpload()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.has_photo', true)
            ->assertJsonMissingPath('data.photo_path');
        $this->get("/api/v1/patients/{$patient->id}/photo")->assertOk();

        $foreignClinic = $this->makeClinic('OTHER');
        $this->actingAs($this->user('RECEPTIONIST', $foreignClinic, 'foreign'), 'sanctum')
            ->get("/api/v1/patients/{$patient->id}/photo", ['Accept' => 'application/json'])
            ->assertNotFound();
    }

    public function test_walk_in_goes_straight_to_the_waiting_room_and_doctor_calls_then_consults(): void
    {
        $patient = $this->patient('Ousmane', 'Brahim');
        $this->actingAs($this->receptionist, 'sanctum');
        $walkIn = $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'is_walk_in' => true, 'reason' => 'Fièvre depuis 3 jours',
        ])->assertCreated()->assertJsonPath('data.is_walk_in', true);
        $appointmentId = $walkIn->json('data.id');
        $this->assertNotNull(Appointment::find($appointmentId)->checked_in_at);

        $this->postJson('/api/v1/appointments', ['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'is_walk_in' => true])
            ->assertUnprocessable();

        // Nurse triage: vitals appear in the queue.
        $this->actingAs($this->nurse, 'sanctum')
            ->postJson("/api/v1/patients/{$patient->id}/vitals", ['temperature' => 39.2, 'systolic_bp' => 120, 'diastolic_bp' => 80])
            ->assertCreated();
        $this->getJson('/api/v1/appointments/queue')->assertOk()
            ->assertJsonPath('data.0.stage', 'WAITING')
            ->assertJsonPath('data.0.vitals.alerts.0', 'Fièvre');

        // Only the assigned doctor may call the patient.
        $this->actingAs($this->otherDoctor, 'sanctum')->postJson("/api/v1/appointments/{$appointmentId}/call")->assertForbidden();
        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/appointments/{$appointmentId}/call")->assertOk();
        $this->getJson('/api/v1/appointments/queue')->assertJsonPath('data.0.stage', 'IN_CONSULTATION');

        $consultation = $this->postJson('/api/v1/consultations', ['appointment_id' => $appointmentId])
            ->assertCreated()
            ->assertJsonPath('data.patient_id', $patient->id)
            ->assertJsonPath('data.appointment_id', $appointmentId)
            ->assertJsonPath('data.sections.0.content', 'Fièvre depuis 3 jours');
        $this->assertSame('COMPLETED', Appointment::find($appointmentId)->status);
        $this->postJson('/api/v1/consultations', ['appointment_id' => $appointmentId])->assertStatus(409);
        $this->getJson('/api/v1/appointments/queue')->assertJsonPath('data.0.consultation_id', $consultation->json('data.id'));
    }

    public function test_check_in_rules(): void
    {
        $patient = $this->patient('Khadidja', 'Adoum');
        $tomorrow = Appointment::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id,
            'appointment_datetime' => now()->addDay()->setTime(9, 0), 'status' => 'SCHEDULED',
        ]);
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/v1/appointments/{$tomorrow->id}/check-in")->assertUnprocessable();

        $today = Appointment::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id,
            'appointment_datetime' => now()->startOfDay()->addMinutes(30), 'status' => 'SCHEDULED',
        ]);
        $this->postJson("/api/v1/appointments/{$today->id}/check-in")->assertOk();
        $this->postJson("/api/v1/appointments/{$today->id}/check-in")->assertUnprocessable();
        $this->postJson("/api/v1/appointments/{$today->id}/call")->assertForbidden();
    }

    public function test_agenda_filters_by_period_and_doctor(): void
    {
        $patient = $this->patient('Mahamat', 'Saleh');
        foreach ([[$this->doctor, 1], [$this->doctor, 3], [$this->otherDoctor, 1], [$this->doctor, 20]] as [$doctor, $days]) {
            Appointment::create([
                'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
                'appointment_datetime' => now()->addDays($days)->setTime(10, 0), 'status' => 'SCHEDULED',
            ]);
        }

        $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/v1/appointments?'.http_build_query(['from' => now()->toDateString(), 'to' => now()->addDays(6)->toDateString(), 'doctor_id' => $this->doctor->id]))
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/appointments?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
    }

    public function test_consultation_needs_reason_and_valid_icd10_diagnosis_to_be_completed(): void
    {
        $patient = $this->patient('Abdoulaye', 'Ali');
        $this->actingAs($this->doctor, 'sanctum');
        $id = $this->postJson('/api/v1/consultations', ['patient_id' => $patient->id, 'consultation_datetime' => now()->toIso8601String()])
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/consultations/{$id}", ['status' => 'COMPLETED'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sections', 'diagnoses']);

        $this->patchJson("/api/v1/consultations/{$id}", ['diagnoses' => [['cim10_code' => 'ZZZ99', 'cim10_description' => 'Inventé', 'is_primary' => true]]])
            ->assertUnprocessable()->assertJsonValidationErrors('diagnoses');
        $this->patchJson("/api/v1/consultations/{$id}", ['diagnoses' => [
            ['cim10_code' => 'B54', 'cim10_description' => 'Paludisme', 'is_primary' => true],
            ['cim10_code' => 'R50.9', 'cim10_description' => 'Fièvre', 'is_primary' => true],
        ]])->assertUnprocessable();

        $this->getJson('/api/v1/cim10?search=palud')->assertOk()->assertJsonFragment(['code' => 'B54']);

        $this->patchJson("/api/v1/consultations/{$id}", [
            'sections' => [['section_number' => 1, 'content' => 'Fièvre et céphalées'], ['section_number' => 3, 'content' => 'T° 39,2']],
            'diagnoses' => [
                ['cim10_code' => 'b54', 'cim10_description' => 'Paludisme', 'is_primary' => true],
                ['cim10_code' => 'NON_CODE', 'cim10_description' => 'Déshydratation légère'],
            ],
            'status' => 'COMPLETED',
        ])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.sections.0.title', 'Motif de consultation')
            ->assertJsonCount(2, 'data.diagnostics');
        $this->assertDatabaseHas('consultations_diagnostics',['consultation_id' => $id, 'cim10_code' => 'B54', 'is_primary' => true]);
        $this->postJson("/api/v1/consultations/{$id}/sign")->assertOk();
    }

    public function test_medical_certificate_is_numbered_immutable_and_cancellable_by_its_author(): void
    {
        $patient = $this->patient('Amina', 'Youssouf');
        $this->actingAs($this->doctor, 'sanctum');
        $this->postJson('/api/v1/certificates', ['patient_id' => $patient->id, 'type' => 'WORK_STOPPAGE', 'content' => 'Repos nécessaire.'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);

        $certificate = $this->postJson('/api/v1/certificates', [
            'patient_id' => $patient->id, 'type' => 'WORK_STOPPAGE', 'start_date' => '2026-10-10', 'end_date' => '2026-10-14',
            'content' => 'Je soussigné certifie que l’état de santé nécessite un arrêt de travail.',
        ])->assertCreated()
            ->assertJsonPath('data.number', 'CM-RECEP-'.now()->year.'-00001')
            ->assertJsonPath('data.days', 5);
        $id = $certificate->json('data.id');
        $this->assertSame(64, strlen($certificate->json('data.signature_hash')));

        $this->putJson("/api/v1/certificates/{$id}", ['content' => 'modifié'])->assertStatus(405);
        $this->actingAs($this->otherDoctor, 'sanctum')->postJson("/api/v1/certificates/{$id}/cancel", ['reason' => 'Erreur de saisie'])->assertForbidden();
        $this->actingAs($this->nurse, 'sanctum')->postJson('/api/v1/certificates', ['patient_id' => $patient->id, 'type' => 'GENERAL', 'content' => 'Tentative infirmière.'])->assertForbidden();

        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/certificates/{$id}/cancel", ['reason' => 'Erreur de dates'])->assertOk();
        $this->postJson("/api/v1/certificates/{$id}/cancel", ['reason' => 'Encore une fois'])->assertUnprocessable();
        $this->assertDatabaseHas('medical_certificates', ['id' => $id, 'cancellation_reason' => 'Erreur de dates']);
    }

    /** A real 1×1 PNG, so the test does not need the GD extension. */
    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        return new UploadedFile($path, 'photo.png', 'image/png', null, true);
    }

    private function patient(string $first, string $last, ?string $birth = null, ?string $phone = null): Patient
    {
        static $sequence = 0;
        $sequence++;

        return Patient::create([
            'clinic_id' => $this->clinic->id,
            'patient_unique_id' => 'RECEP-2026-'.str_pad((string) (900000 + $sequence), 6, '0', STR_PAD_LEFT),
            'first_name' => $first, 'last_name' => $last, 'date_of_birth' => $birth, 'phone' => $phone,
        ]);
    }

    private function user(string $role, Clinic $clinic, string $prefix = 'user'): User
    {
        return User::factory()->create([
            'email' => $prefix.'-'.strtolower($role).'-'.$clinic->id.'@example.test',
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    private function makeClinic(string $code): Clinic
    {
        return Clinic::create([
            'name' => "Clinique {$code}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => 'BASIC', 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
    }
}
