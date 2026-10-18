<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\ConsultationTask;
use App\Models\ImagingExam;
use App\Models\ImagingRequest;
use App\Models\Invoice;
use App\Models\LabExam;
use App\Models\LabRequest;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PharmacyInventory;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VitalSign;
use App\Support\CurrentClinic;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'SECRET_A';

    private Clinic $clinicA;
    private Clinic $clinicB;
    /** @var array<string, int> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->clinicA = $this->makeClinic('CLINIC_A');
        $this->clinicB = $this->makeClinic('CLINIC_B');

        Model::unguarded(function () {
            $clinic = $this->clinicA->id;
            $doctor = $this->user('DOCTOR', $this->clinicA, 'doctor-a');
            $nurse = $this->user('NURSE', $this->clinicA, 'nurse-a');
            $patient = Patient::create(['clinic_id' => $clinic, 'patient_unique_id' => 'CLINIC_A-2026-000001', 'first_name' => 'Awa', 'last_name' => self::MARKER]);
            $consultation = Consultation::create(['clinic_id' => $clinic, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'consultation_datetime' => now(), 'status' => 'COMPLETED']);
            $medication = Medication::create(['clinic_id' => $clinic, 'name' => self::MARKER.' Paracétamol', 'dosage' => '500 mg', 'form' => 'Comprimé', 'price' => 500]);
            PharmacyInventory::create(['clinic_id' => $clinic, 'medication_id' => $medication->id, 'quantity' => 50, 'unit_cost' => 200, 'selling_price' => 500, 'min_threshold' => 5]);
            $prescription = Prescription::create(['clinic_id' => $clinic, 'consultation_id' => $consultation->id, 'doctor_id' => $doctor->id, 'prescribed_at' => now(), 'status' => 'SIGNED', 'notes' => self::MARKER]);
            $prescription->items()->create(['medication_id' => $medication->id, 'dosage_prescribed' => '1 cp', 'frequency' => '3/j', 'duration_days' => 3, 'quantity' => 9]);
            $labExam = LabExam::create(['clinic_id' => $clinic, 'exam_type' => 'HEMATOLOGY', 'name' => self::MARKER.' NFS']);
            $labRequest = LabRequest::create(['clinic_id' => $clinic, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'consultation_id' => $consultation->id, 'requested_at' => now(), 'status' => 'READY']);
            $imagingExam = ImagingExam::create(['clinic_id' => $clinic, 'exam_type' => 'XRAY', 'name' => self::MARKER.' Radio thorax']);
            $imagingRequest = ImagingRequest::create(['clinic_id' => $clinic, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'exam_id' => $imagingExam->id, 'consultation_id' => $consultation->id, 'requested_at' => now(), 'status' => 'IN_PROGRESS']);
            $invoice = Invoice::create(['clinic_id' => $clinic, 'patient_id' => $patient->id, 'invoice_number' => 'FAC-'.self::MARKER, 'subtotal' => 10000, 'tax_amount' => 1800, 'tax_rate' => 18, 'total_amount' => 11800, 'status' => 'ISSUED', 'issued_at' => now()]);
            $appointment = Appointment::create(['clinic_id' => $clinic, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'appointment_datetime' => now()->addDays(2)->setTime(10, 0), 'status' => 'SCHEDULED', 'cancellation_reason' => null]);
            $task = ConsultationTask::create(['consultation_id' => $consultation->id, 'assigned_to' => $nurse->id, 'task_description' => self::MARKER.' injection', 'status' => 'PENDING']);
            VitalSign::create(['clinic_id' => $clinic, 'patient_id' => $patient->id, 'recorded_by' => $nurse->id, 'heart_rate' => 72, 'notes' => self::MARKER, 'recorded_at' => now()]);
            UserNotification::create(['clinic_id' => $clinic, 'user_id' => $doctor->id, 'type' => 'INFO', 'title' => self::MARKER, 'message' => self::MARKER]);

            $this->a = [
                'clinic' => $clinic, 'doctor' => $doctor->id, 'patient' => $patient->id, 'consultation' => $consultation->id,
                'medication' => $medication->id, 'prescription' => $prescription->id, 'labExam' => $labExam->id,
                'labRequest' => $labRequest->id, 'imagingRequest' => $imagingRequest->id, 'invoice' => $invoice->id,
                'appointment' => $appointment->id, 'task' => $task->id,
            ];
        });
    }

    /** @return array<string, array{string, string, string, array<string, mixed>}> */
    public static function forbiddenActions(): array
    {
        return [
            'admin lit le patient' => ['ADMIN_CLINIC', 'GET', '/patients/{patient}', []],
            'admin modifie le patient' => ['ADMIN_CLINIC', 'PATCH', '/patients/{patient}', ['first_name' => 'Pirate']],
            'admin archive le patient' => ['ADMIN_CLINIC', 'DELETE', '/patients/{patient}', []],
            'admin lit le rendez-vous' => ['ADMIN_CLINIC', 'GET', '/appointments/{appointment}', []],
            'admin reporte le rendez-vous' => ['ADMIN_CLINIC', 'PATCH', '/appointments/{appointment}', ['appointment_time' => '15:00']],
            'admin annule le rendez-vous' => ['ADMIN_CLINIC', 'DELETE', '/appointments/{appointment}', ['cancellation_reason' => 'x']],
            'admin lit la facture' => ['ADMIN_CLINIC', 'GET', '/invoices/{invoice}', []],
            'admin encaisse la facture' => ['ADMIN_CLINIC', 'POST', '/invoices/{invoice}/payments', ['amount' => 1000, 'payment_method' => 'CASH']],
            'admin lit le médecin' => ['ADMIN_CLINIC', 'GET', '/users/{doctor}', []],
            'admin modifie le médecin' => ['ADMIN_CLINIC', 'PATCH', '/users/{doctor}', ['name' => 'Pirate']],
            'admin lit la clinique' => ['ADMIN_CLINIC', 'GET', '/clinics/{clinic}', []],
            'admin modifie la clinique' => ['ADMIN_CLINIC', 'PATCH', '/clinics/{clinic}', ['name' => 'Pirate']],
            'admin lit l analyse' => ['ADMIN_CLINIC', 'GET', '/lab-requests/{labRequest}', []],
            'admin lit l imagerie' => ['ADMIN_CLINIC', 'GET', '/imaging-requests/{imagingRequest}', []],
            'médecin lit la consultation' => ['DOCTOR', 'GET', '/consultations/{consultation}', []],
            'médecin modifie la consultation' => ['DOCTOR', 'PATCH', '/consultations/{consultation}', ['notes' => 'Pirate']],
            'médecin signe la consultation' => ['DOCTOR', 'POST', '/consultations/{consultation}/sign', []],
            'médecin prescrit une tâche' => ['DOCTOR', 'POST', '/consultations/{consultation}/tasks', ['assigned_to' => 1, 'task_description' => 'x']],
            'médecin lit l ordonnance' => ['DOCTOR', 'GET', '/prescriptions/{prescription}', []],
            'médecin prescrit sur la consultation' => ['DOCTOR', 'POST', '/prescriptions', ['consultation_id' => '{consultation}', 'items' => [['medication_id' => '{medication}', 'dosage_prescribed' => '1', 'frequency' => '1', 'duration_days' => 1, 'quantity' => 1]]]],
            'médecin lit le dossier' => ['DOCTOR', 'GET', '/patients/{patient}/timeline', []],
            'médecin lit les constantes' => ['DOCTOR', 'GET', '/patients/{patient}/vitals', []],
            'médecin demande une analyse' => ['DOCTOR', 'POST', '/lab-requests', ['patient_id' => '{patient}', 'consultation_id' => '{consultation}', 'exam_ids' => ['{labExam}']]],
            'infirmier saisit des constantes' => ['NURSE', 'POST', '/patients/{patient}/vitals', ['heart_rate' => 80]],
            'infirmier termine la tâche' => ['NURSE', 'PATCH', '/care-tasks/{task}', ['status' => 'COMPLETED']],
            'pharmacien délivre l ordonnance' => ['PHARMACIST', 'POST', '/prescriptions/{prescription}/dispense', []],
            'pharmacien ajuste le stock' => ['PHARMACIST', 'PATCH', '/medications/{medication}/stock', ['quantity_change' => -10, 'reason' => 'x']],
            'pharmacien déclare une interaction' => ['PHARMACIST', 'POST', '/drug-interactions', ['drug_id_1' => '{medication}', 'drug_id_2' => '{medication}', 'severity' => 'LOW', 'description' => 'x']],
            'laborantin saisit les résultats' => ['LABORATORY_TECHNICIAN', 'POST', '/lab-requests/{labRequest}/results', ['results' => []]],
            'laborantin signe les résultats' => ['LABORATORY_TECHNICIAN', 'POST', '/lab-requests/{labRequest}/sign-results', []],
            'radiologue rédige le compte rendu' => ['RADIOLOGIST', 'POST', '/imaging-requests/{imagingRequest}/report', ['findings' => 'x', 'conclusion' => 'x']],
            'caissier lit la facture' => ['CASHIER', 'GET', '/invoices/{invoice}', []],
            'réceptionniste réserve pour le patient' => ['RECEPTIONIST', 'POST', '/appointments', ['patient_id' => '{patient}', 'doctor_id' => '{doctor}', 'appointment_date' => '2030-01-15', 'appointment_time' => '09:00']],
        ];
    }

    #[DataProvider('forbiddenActions')]
    public function test_clinic_b_cannot_reach_clinic_a_data(string $role, string $method, string $uri, array $payload): void
    {
        $actor = $this->user($role, $this->clinicB, 'actor');

        $response = $this->actingAs($actor, 'sanctum')->json($method, '/api/v1'.$this->fill($uri), $this->fill($payload));

        $this->assertGreaterThanOrEqual(400, $response->status(), "{$method} {$uri} a répondu {$response->status()}");
        $this->assertStringNotContainsString(self::MARKER, $response->getContent());
    }

    /** @return array<string, array{string, string}> */
    public static function listEndpoints(): array
    {
        return [
            'patients' => ['ADMIN_CLINIC', '/patients'],
            'rendez-vous' => ['ADMIN_CLINIC', '/appointments'],
            'consultations' => ['DOCTOR', '/consultations'],
            'ordonnances' => ['PHARMACIST', '/prescriptions'],
            'médicaments' => ['PHARMACIST', '/medications'],
            'stock' => ['PHARMACIST', '/pharmacy/stock'],
            'interactions' => ['PHARMACIST', '/drug-interactions'],
            'analyses' => ['LABORATORY_TECHNICIAN', '/lab-requests'],
            'catalogue analyses' => ['LABORATORY_TECHNICIAN', '/lab-exams'],
            'imagerie' => ['RADIOLOGIST', '/imaging-requests'],
            'catalogue imagerie' => ['RADIOLOGIST', '/imaging-exams'],
            'factures' => ['CASHIER', '/invoices'],
            'tâches de soins' => ['NURSE', '/care-tasks'],
            'utilisateurs' => ['ADMIN_CLINIC', '/users'],
            'journal d audit' => ['ADMIN_CLINIC', '/audit-logs'],
            'notifications' => ['DOCTOR', '/notifications'],
            'cliniques' => ['ADMIN_CLINIC', '/clinics'],
            'rapports' => ['ADMIN_CLINIC', '/reports/overview'],
            'tableau de bord' => ['ADMIN_CLINIC', '/dashboard'],
        ];
    }

    #[DataProvider('listEndpoints')]
    public function test_lists_of_clinic_b_never_contain_clinic_a_data(string $role, string $uri): void
    {
        $actor = $this->user($role, $this->clinicB, 'actor');

        $response = $this->actingAs($actor, 'sanctum')->getJson('/api/v1'.$uri);

        $response->assertOk();
        $this->assertStringNotContainsString(self::MARKER, $response->getContent());
    }

    public function test_clinic_header_cannot_be_spoofed(): void
    {
        $actor = $this->user('ADMIN_CLINIC', $this->clinicB, 'actor');

        $this->actingAs($actor, 'sanctum')
            ->withHeader('X-Clinic-ID', (string) $this->clinicA->id)
            ->getJson('/api/v1/patients')
            ->assertForbidden();
    }

    public function test_model_scope_hides_other_clinics_even_without_controller_checks(): void
    {
        CurrentClinic::set($this->clinicB->id);
        try {
            $this->assertNull(Patient::find($this->a['patient']));
            $this->assertSame(0, Consultation::count());
            $this->assertSame(0, Invoice::where('id', $this->a['invoice'])->count());
        } finally {
            CurrentClinic::clear();
        }
    }

    public function test_writing_into_another_clinic_is_refused(): void
    {
        CurrentClinic::set($this->clinicB->id);
        try {
            $this->expectException(LogicException::class);
            Patient::create(['clinic_id' => $this->clinicA->id, 'patient_unique_id' => 'X-1', 'first_name' => 'X', 'last_name' => 'Y']);
        } finally {
            CurrentClinic::clear();
        }
    }

    private function fill(string|array $value): string|array|int
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->fill($item), $value);
        }
        if (preg_match('/^\{(\w+)\}$/', $value, $match)) {
            return $this->a[$match[1]];
        }

        return preg_replace_callback('/\{(\w+)\}/', fn ($match) => (string) $this->a[$match[1]], $value);
    }

    private function user(string $role, Clinic $clinic, string $prefix): User
    {
        return User::factory()->create([
            'email' => $prefix.'-'.strtolower($role).'-'.$clinic->id.'@example.test',
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
            'name' => $clinic->is($this->clinicA ?? null) ? self::MARKER.' '.$role : $role,
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
