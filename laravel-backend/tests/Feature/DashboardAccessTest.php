<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->clinic = $this->makeClinic('CLINIC_A');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_receptionist_only_gets_reception_modules(): void
    {
        $user = $this->userWithRole('RECEPTIONIST');

        $modules = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->json('data.modules');

        $this->assertEqualsCanonicalizing(['patients', 'appointments', 'reception', 'staff', 'notifications', 'settings'], $modules);
    }

    public function test_patient_gets_only_personal_care_modules_and_records(): void
    {
        $patient = $this->makePatient($this->clinic);
        $otherPatient = $this->makePatient($this->clinic);
        $patientUser = $this->userWithRole('PATIENT');
        $patient->user()->associate($patientUser);
        $patient->save();
        $doctor = $this->userWithRole('DOCTOR');
        $ownAppointment = $this->makeAppointment($this->clinic, $patient, $doctor);
        $this->makeAppointment($this->clinic, $otherPatient, $doctor);
        $ownSignedConsultation = Consultation::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'consultation_datetime' => now(),
            'status' => 'SIGNED',
        ]);
        $ownDraftConsultation = Consultation::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'consultation_datetime' => now(),
            'status' => 'DRAFT',
        ]);
        $otherSignedConsultation = Consultation::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $otherPatient->id,
            'doctor_id' => $doctor->id,
            'consultation_datetime' => now(),
            'status' => 'SIGNED',
        ]);

        $modules = $this->actingAs($patientUser, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->json('data.modules');
        $this->assertEqualsCanonicalizing(
            ['patients', 'appointments', 'consultations', 'prescriptions', 'portal', 'notifications', 'settings'],
            $modules,
        );

        $this->getJson('/api/v1/patients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id);
        $this->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownAppointment->id);
        $this->getJson('/api/v1/consultations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownSignedConsultation->id);
        $this->getJson('/api/v1/patients/'.$otherPatient->id)->assertNotFound();
        $this->getJson('/api/v1/consultations/'.$ownDraftConsultation->id)->assertNotFound();
        $this->getJson('/api/v1/consultations/'.$otherSignedConsultation->id)->assertNotFound();
        $this->getJson('/api/v1/medications')->assertForbidden();
        $this->postJson('/api/v1/patients', [
            'first_name' => 'Not allowed',
            'last_name' => 'Patient',
        ])->assertForbidden();
    }

    public function test_cashier_only_gets_billing_modules(): void
    {
        $user = $this->userWithRole('CASHIER');

        $modules = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->json('data.modules');

        $this->assertEqualsCanonicalizing(['patients', 'invoices', 'staff', 'notifications', 'settings'], $modules);
    }

    public function test_doctor_cannot_see_admin_screens(): void
    {
        $user = $this->userWithRole('DOCTOR');

        $modules = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->json('data.modules');

        $this->assertNotContains('users', $modules);
        $this->assertNotContains('audit', $modules);
        $this->assertNotContains('reports', $modules);
        $this->assertNotContains('clinics', $modules);
        $this->assertContains('consultations', $modules);
        $this->assertContains('lab', $modules);
        $this->assertContains('imaging', $modules);
    }

    public function test_lab_and_imaging_modules_are_granted_separately_by_role(): void
    {
        $nurseModules = $this->actingAs($this->userWithRole('NURSE'), 'sanctum')
            ->getJson('/api/v1/me')->json('data.modules');
        $radiologistModules = $this->actingAs($this->userWithRole('RADIOLOGIST'), 'sanctum')
            ->getJson('/api/v1/me')->json('data.modules');
        $adminModules = $this->actingAs($this->userWithRole('ADMIN_CLINIC'), 'sanctum')
            ->getJson('/api/v1/me')->json('data.modules');
        $superAdminModules = $this->actingAs($this->userWithRole('SUPER_ADMIN', null), 'sanctum')
            ->getJson('/api/v1/me')->json('data.modules');

        $this->assertContains('lab', $nurseModules);
        $this->assertNotContains('imaging', $nurseModules);
        $this->assertContains('imaging', $radiologistModules);
        $this->assertNotContains('lab', $radiologistModules);
        $this->assertContains('lab', $adminModules);
        $this->assertContains('imaging', $adminModules);
        // Medical secrecy: the platform operator never opens clinical modules.
        $this->assertNotContains('lab', $superAdminModules);
        $this->assertNotContains('imaging', $superAdminModules);
    }

    public function test_super_admin_gets_clinics_module(): void
    {
        $user = $this->userWithRole('SUPER_ADMIN', null);

        $modules = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->json('data.modules');

        $this->assertContains('clinics', $modules);
    }

    public function test_dashboard_widgets_differ_by_role(): void
    {
        $doctor = $this->actingAs($this->userWithRole('DOCTOR'), 'sanctum')->getJson('/api/v1/dashboard')->json('data');
        $cashier = $this->actingAs($this->userWithRole('CASHIER'), 'sanctum')->getJson('/api/v1/dashboard')->json('data');

        $doctorKeys = array_column($doctor['widgets'], 'key');
        $cashierKeys = array_column($cashier['widgets'], 'key');

        $this->assertSame('DOCTOR', $doctor['role']);
        $this->assertContains('prescriptions_to_sign', $doctorKeys);
        $this->assertNotContains('invoices_outstanding_count', $doctorKeys);
        $this->assertContains('invoices_outstanding_count', $cashierKeys);
        $this->assertNotContains('prescriptions_to_sign', $cashierKeys);
    }

    public function test_doctor_widget_counts_only_own_appointments_today(): void
    {
        $doctorA = $this->userWithRole('DOCTOR');
        $doctorB = $this->userWithRole('DOCTOR');
        $patient = $this->makePatient($this->clinic);
        $this->makeAppointment($this->clinic, $patient, $doctorA);
        $this->makeAppointment($this->clinic, $patient, $doctorB);

        $widgets = $this->actingAs($doctorA, 'sanctum')->getJson('/api/v1/dashboard')->json('data.widgets');

        $this->assertSame(1, $this->widgetValue($widgets, 'appointments_today'));
    }

    public function test_admin_dashboard_ignores_other_clinics(): void
    {
        $otherClinic = $this->makeClinic('CLINIC_B');
        $this->makePatient($otherClinic);
        $this->makePatient($this->clinic);
        $admin = $this->userWithRole('ADMIN_CLINIC');

        $widgets = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard')->json('data.widgets');

        $this->assertSame(1, $this->widgetValue($widgets, 'patients_total'));
    }

    public function test_hr_manager_has_no_dashboard_widgets_until_hr_module_exists(): void
    {
        $hr = $this->userWithRole('HR_MANAGER');

        $widgets = $this->actingAs($hr, 'sanctum')->getJson('/api/v1/dashboard')->json('data.widgets');

        $this->assertSame([], $widgets);
    }

    public function test_only_admin_can_create_exam_catalog_entries(): void
    {
        $payload = ['exam_type' => 'HEMATOLOGY', 'name' => 'NFS', 'price' => 5000];

        $this->actingAs($this->userWithRole('NURSE'), 'sanctum')
            ->postJson('/api/v1/lab-exams', $payload)
            ->assertForbidden();

        $this->actingAs($this->userWithRole('ADMIN_CLINIC'), 'sanctum')
            ->postJson('/api/v1/lab-exams', $payload)
            ->assertSuccessful();
    }

    private function userWithRole(string $role, ?Clinic $clinic = null): User
    {
        $clinic ??= $role === 'SUPER_ADMIN' ? null : $this->clinic;

        return User::factory()->create([
            'clinic_id' => $clinic?->id,
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
    }

    private function makeClinic(string $code): Clinic
    {
        return Clinic::create([
            'name' => "Clinic {$code}",
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => $code,
            'subscription_plan' => 'BASIC',
            'admin_email' => strtolower($code).'@example.test',
            'status' => 'ACTIVE',
        ]);
    }

    private function makePatient(Clinic $clinic): Patient
    {
        return Patient::create([
            'clinic_id' => $clinic->id,
            'patient_unique_id' => strtoupper(Str::random(12)),
            'first_name' => 'Test',
            'last_name' => 'Patient',
        ]);
    }

    private function makeAppointment(Clinic $clinic, Patient $patient, User $doctor): Appointment
    {
        return Appointment::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'slot_duration' => 30,
            'appointment_datetime' => today()->setTime(12, 0),
            'status' => 'SCHEDULED',
        ]);
    }

    private function widgetValue(array $widgets, string $key): mixed
    {
        return collect($widgets)->firstWhere('key', $key)['value'] ?? null;
    }
}
