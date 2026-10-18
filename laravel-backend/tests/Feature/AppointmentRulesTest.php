<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentRulesTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $receptionist;
    private User $doctor;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Rules Clinic', 'city' => 'Dakar', 'country' => 'Sénégal', 'code' => 'RULES',
            'subscription_plan' => 'BASIC', 'admin_email' => 'rules@example.test', 'status' => 'ACTIVE',
        ]);
        $this->receptionist = $this->userWithRole('RECEPTIONIST');
        $this->doctor = $this->userWithRole('DOCTOR');
        $this->patient = $this->makePatient();
    }

    public function test_doctor_cannot_be_double_booked(): void
    {
        $this->book($this->doctor, $this->patient, '10:00')->assertCreated();

        $this->book($this->doctor, $this->makePatient(), '10:15')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Le médecin a déjà un rendez-vous sur ce créneau (30 minutes).');
    }

    public function test_patient_cannot_be_double_booked_with_another_doctor(): void
    {
        $this->book($this->doctor, $this->patient, '10:00')->assertCreated();

        $this->book($this->userWithRole('DOCTOR'), $this->patient, '10:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Le patient a déjà un rendez-vous sur ce créneau (30 minutes).');
    }

    public function test_next_slot_is_free(): void
    {
        $this->book($this->doctor, $this->patient, '10:00')->assertCreated();

        $this->book($this->doctor, $this->makePatient(), '10:30')->assertCreated();
    }

    public function test_cancelled_appointment_frees_the_slot(): void
    {
        $id = $this->book($this->doctor, $this->patient, '10:00')->json('data.id');
        $this->asDoctor()->deleteJson("/api/v1/appointments/{$id}", ['cancellation_reason' => 'Patient indisponible'])->assertOk();

        $this->book($this->doctor, $this->makePatient(), '10:00')->assertCreated();
    }

    public function test_rescheduling_into_a_taken_slot_is_rejected(): void
    {
        $this->book($this->doctor, $this->patient, '10:00')->assertCreated();
        $id = $this->book($this->doctor, $this->makePatient(), '11:00')->json('data.id');

        $this->asDoctor()->patchJson("/api/v1/appointments/{$id}", ['appointment_time' => '10:10'])
            ->assertUnprocessable();
    }

    public function test_cancellation_keeps_history_and_requires_a_reason(): void
    {
        $id = $this->book($this->doctor, $this->patient, '10:00')->json('data.id');

        $this->asDoctor()->deleteJson("/api/v1/appointments/{$id}")->assertUnprocessable();
        $this->asDoctor()->deleteJson("/api/v1/appointments/{$id}", ['cancellation_reason' => 'Urgence'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('appointments', ['id' => $id, 'status' => 'CANCELLED', 'deleted_at' => null]);
    }

    public function test_closed_appointment_cannot_be_modified(): void
    {
        $id = $this->book($this->doctor, $this->patient, '10:00')->json('data.id');
        $this->asDoctor()->deleteJson("/api/v1/appointments/{$id}", ['cancellation_reason' => 'Urgence'])->assertOk();

        $this->asDoctor()->patchJson("/api/v1/appointments/{$id}", ['appointment_time' => '15:00'])
            ->assertUnprocessable();
    }

    public function test_future_appointment_cannot_be_marked_completed(): void
    {
        $id = $this->book($this->doctor, $this->patient, '10:00')->json('data.id');

        $this->asDoctor()->patchJson("/api/v1/appointments/{$id}", ['status' => 'COMPLETED'])
            ->assertUnprocessable();
    }

    public function test_patient_appointment_request_requires_clinic_approval_before_booking(): void
    {
        $patientUser = $this->userWithRole('PATIENT');
        $this->patient->user()->associate($patientUser);
        $this->patient->save();
        $date = now()->addDays(2)->toDateString();

        $createdRequest = $this->actingAs($patientUser, 'sanctum')
            ->postJson('/api/v1/appointment-requests', [
                'doctor_id' => $this->doctor->id,
                'requested_date' => $date,
                'requested_time' => '10:00',
            ])
            ->assertCreated();
        $this->assertSame('PENDING', $createdRequest->json('data.status'), json_encode($createdRequest->json()));
        $this->assertSame($this->patient->id, $createdRequest->json('data.patient.id'), json_encode($createdRequest->json()));
        $requestId = $createdRequest->json('data.id');

        $this->actingAs($patientUser, 'sanctum')->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/appointment-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId);

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/v1/appointment-requests/{$requestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertDatabaseHas('appointments', [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'status' => 'SCHEDULED',
        ]);
    }

    public function test_patient_appointment_request_is_not_approved_if_the_slot_is_taken(): void
    {
        $patientUser = $this->userWithRole('PATIENT');
        $this->patient->user()->associate($patientUser);
        $this->patient->save();
        $this->book($this->doctor, $this->makePatient(), '10:00')->assertCreated();

        $requestId = $this->actingAs($patientUser, 'sanctum')
            ->postJson('/api/v1/appointment-requests', [
                'doctor_id' => $this->doctor->id,
                'requested_date' => now()->addDays(2)->toDateString(),
                'requested_time' => '10:00',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson("/api/v1/appointment-requests/{$requestId}/approve")
            ->assertUnprocessable();
        $this->assertDatabaseHas('appointment_requests', ['id' => $requestId, 'status' => 'PENDING']);
    }

    private function book(User $doctor, Patient $patient, string $time)
    {
        return $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => now()->addDays(2)->toDateString(),
            'appointment_time' => $time,
        ]);
    }

    private function asDoctor(): static
    {
        return $this->actingAs($this->doctor, 'sanctum');
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'clinic_id' => $this->clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    private function makePatient(): Patient
    {
        return Patient::create([
            'clinic_id' => $this->clinic->id,
            'patient_unique_id' => strtoupper(Str::random(12)),
            'first_name' => 'Test',
            'last_name' => 'Patient',
        ]);
    }
}
