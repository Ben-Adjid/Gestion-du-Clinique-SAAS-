<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\ImagingExam;
use App\Models\LabExam;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\PharmacyInventory;
use App\Models\PharmacyLot;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalServiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pharmacist_dispenses_a_signed_prescription_atomically_and_only_once(): void
    {
        [$clinic, $doctor, $patient] = $this->createClinicAndDoctor();
        $pharmacist = $this->createUser($clinic, 'PHARMACIST', ['dispense:medication']);
        $consultation = Consultation::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'consultation_datetime' => now(),
            'status' => 'COMPLETED',
        ]);
        $medication = Medication::create([
            'clinic_id' => $clinic->id,
            'name' => 'Paracetamol',
            'dosage' => '500 mg',
            'form' => 'Comprimé',
            'price' => 2.50,
        ]);
        $inventory = PharmacyInventory::create([
            'clinic_id' => $clinic->id,
            'medication_id' => $medication->id,
            'quantity' => 8,
            'unit_cost' => 1,
            'selling_price' => 2.50,
            'min_threshold' => 2,
        ]);
        $lot = PharmacyLot::create([
            'inventory_id' => $inventory->id,
            'lot_number' => 'LOT-A',
            'manufacturing_date' => now()->subMonths(3)->toDateString(),
            'expiration_date' => now()->addYear()->toDateString(),
            'quantity' => 8,
            'initial_quantity' => 8,
        ]);
        $prescription = $this->createPrescription($clinic, $doctor, $consultation, $medication, 3);

        $this->actingAs($pharmacist, 'sanctum')
            ->postJson("/api/v1/prescriptions/{$prescription->id}/dispense")
            ->assertOk()
            ->assertJsonPath('data.status', 'DISPENSED')
            ->assertJsonPath('sale.total_amount', 7.5);

        $this->assertDatabaseHas('pharmacy_inventory', ['id' => $inventory->id, 'quantity' => 5]);
        $this->assertDatabaseHas('pharmacy_sales', [
            'prescription_id' => $prescription->id,
            'patient_id' => $patient->id,
            'dispensed_by' => $pharmacist->id,
        ]);
        $this->assertDatabaseHas('pharmacy_movements', [
            'inventory_id' => $inventory->id,
            'lot_id' => $lot->id,
            'reference_id' => 'prescription:'.$prescription->id,
            'type' => 'OUT',
            'quantity' => 3,
        ]);

        $this->postJson("/api/v1/prescriptions/{$prescription->id}/dispense")
            ->assertUnprocessable();
        $this->assertDatabaseCount('pharmacy_sales', 1);
        $this->assertDatabaseHas('pharmacy_inventory', ['id' => $inventory->id, 'quantity' => 5]);

        $oversizedPrescription = $this->createPrescription($clinic, $doctor, $consultation, $medication, 6);
        $this->postJson("/api/v1/prescriptions/{$oversizedPrescription->id}/dispense")
            ->assertUnprocessable();
        $this->assertDatabaseCount('pharmacy_sales', 1);
        $this->assertDatabaseHas('pharmacy_inventory', ['id' => $inventory->id, 'quantity' => 5]);
    }

    public function test_laboratory_technician_records_and_signs_results_for_a_doctors_request(): void
    {
        [$clinic, $doctor, $patient] = $this->createClinicAndDoctor(['create:lab_request']);
        $technician = $this->createUser($clinic, 'LABORATORY_TECHNICIAN', [
            'read:lab_request', 'update:lab_request', 'collect:lab_sample', 'submit:lab_results', 'sign:lab_results',
        ]);
        $consultation = Consultation::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'consultation_datetime' => now(),
            'status' => 'COMPLETED',
        ]);
        $exam = LabExam::create([
            'clinic_id' => $clinic->id,
            'exam_type' => 'HEMATOLOGY',
            'name' => 'NFS',
            'price' => 5000,
            'unit' => 'g/dL',
            'reference_min' => 12,
            'reference_max' => 16,
        ]);

        $created = $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/v1/lab-requests', [
                'consultation_id' => $consultation->id,
                'exam_ids' => [$exam->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING');
        $requestId = $created->json('data.id');

        // Results cannot be entered before the sample is collected.
        $this->actingAs($technician, 'sanctum')
            ->postJson("/api/v1/lab-requests/{$requestId}/results", ['exam_id' => $exam->id, 'result_value' => '9,8'])
            ->assertUnprocessable();
        $collected = $this->postJson("/api/v1/lab-requests/{$requestId}/collect", ['sample_type' => 'Sang veineux'])
            ->assertOk()
            ->assertJsonPath('data.status', 'RECEIVED');
        $this->assertMatchesRegularExpression('/^L\d+-\d{6}-0001$/', $collected->json('data.sample_number'));
        $this->patchJson("/api/v1/lab-requests/{$requestId}", ['status' => 'IN_PROGRESS'])
            ->assertOk();
        $this->postJson("/api/v1/lab-requests/{$requestId}/results", [
            'exam_id' => $exam->id,
            'result_value' => '9,8',
        ])->assertOk()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.results.0.flag', 'LOW')
            ->assertJsonPath('data.results.0.unit', 'g/dL');
        $this->postJson("/api/v1/lab-requests/{$requestId}/sign-results")
            ->assertOk()
            ->assertJsonPath('data.status', 'SIGNED')
            ->assertJsonPath('data.results.0.status', 'SIGNED');

        $this->assertDatabaseHas('lab_results', [
            'request_id' => $requestId,
            'exam_id' => $exam->id,
            'validator_id' => $technician->id,
            'status' => 'SIGNED',
        ]);
    }

    public function test_radiologist_completes_and_signs_an_imaging_report(): void
    {
        [$clinic, $doctor, $patient] = $this->createClinicAndDoctor(['create:imaging_request']);
        $radiologist = $this->createUser($clinic, 'RADIOLOGIST', [
            'read:imaging', 'update:imaging', 'create:imaging_report',
        ]);
        $consultation = Consultation::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'consultation_datetime' => now(),
            'status' => 'COMPLETED',
        ]);
        $exam = ImagingExam::create([
            'clinic_id' => $clinic->id,
            'exam_type' => 'XRAY',
            'name' => 'Radiographie thoracique',
            'price' => 15000,
        ]);
        $created = $this->actingAs($doctor, 'sanctum')
            ->postJson('/api/v1/imaging-requests', [
                'consultation_id' => $consultation->id,
                'exam_id' => $exam->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING');
        $requestId = $created->json('data.id');

        $this->actingAs($radiologist, 'sanctum')
            ->patchJson("/api/v1/imaging-requests/{$requestId}", ['status' => 'IN_PROGRESS'])
            ->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->postJson("/api/v1/imaging-requests/{$requestId}/report", [
            'report_text' => 'Examen sans anomalie significative.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'READY');
        $this->postJson("/api/v1/imaging-requests/{$requestId}/sign-report")
            ->assertOk()
            ->assertJsonPath('data.status', 'SIGNED')
            ->assertJsonPath('data.reports.0.radiologist_id', $radiologist->id);

        $this->assertDatabaseHas('imaging_reports', [
            'request_id' => $requestId,
            'radiologist_id' => $radiologist->id,
        ]);
    }

    private function createClinicAndDoctor(array $extraDoctorPermissions = []): array
    {
        $clinic = Clinic::create([
            'name' => 'Clinique workflows',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'WORKFLOWS',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'workflows@example.test',
            'status' => 'ACTIVE',
        ]);
        $doctor = $this->createUser($clinic, 'DOCTOR', [
            'create:consultation',
            'read:consultation',
            ...$extraDoctorPermissions,
        ]);
        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'patient_unique_id' => 'WORKFLOWS-'.uniqid(),
            'first_name' => 'Awa',
            'last_name' => 'Ndiaye',
        ]);

        return [$clinic, $doctor, $patient];
    }

    private function createUser(Clinic $clinic, string $roleName, array $permissionNames): User
    {
        $role = Role::create(['name' => $roleName, 'display_name' => $roleName]);
        $permissionIds = collect(array_unique($permissionNames))
            ->map(fn (string $name) => Permission::firstOrCreate(
                ['name' => $name],
                ['resource' => explode(':', $name, 2)[1], 'action' => explode(':', $name, 2)[0]],
            )->id);
        $role->permissions()->sync($permissionIds);

        return User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => $role->id]);
    }

    private function createPrescription(
        Clinic $clinic,
        User $doctor,
        Consultation $consultation,
        Medication $medication,
        int $quantity,
    ): Prescription {
        $prescription = Prescription::create([
            'clinic_id' => $clinic->id,
            'consultation_id' => $consultation->id,
            'doctor_id' => $doctor->id,
            'prescribed_at' => now(),
            'status' => 'SIGNED',
        ]);
        $prescription->items()->create([
            'medication_id' => $medication->id,
            'dosage_prescribed' => '1 comprimé',
            'frequency' => 'Une fois par jour',
            'duration_days' => 3,
            'quantity' => $quantity,
        ]);

        return $prescription;
    }
}
