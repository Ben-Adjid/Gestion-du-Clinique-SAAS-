<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may read what. Medical secrecy and need-to-know: each row says which roles may open the
 * data; every other staff role must be refused. A change here is a change of medical policy.
 */
class AccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF = [
        'ADMIN_CLINIC', 'DOCTOR', 'NURSE', 'RECEPTIONIST', 'CASHIER', 'PHARMACIST',
        'LABORATORY_TECHNICIAN', 'RADIOLOGIST', 'HR_MANAGER',
    ];

    private Clinic $clinic;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Politique', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'POL',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'pol@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->patient = Patient::create([
            'clinic_id' => $this->clinic->id, 'patient_unique_id' => 'POL-2026-000001',
            'first_name' => 'Amina', 'last_name' => 'Test', 'gender' => 'F', 'date_of_birth' => '1980-05-05',
        ]);
    }

    /** endpoint (with {patient} placeholder) => roles allowed to read it */
    public static function policies(): array
    {
        return [
            'dossier clinique (diagnostics, notes)' => ['patients/{patient}/clinical-profile', ['DOCTOR', 'NURSE']],
            'constantes vitales' => ['patients/{patient}/vitals', ['DOCTOR', 'NURSE']],
            'historique médical' => ['patients/{patient}/timeline', ['DOCTOR', 'NURSE']],
            'dossier complet exporté' => ['patients/{patient}/export', ['DOCTOR', 'NURSE']],
            'consultations' => ['consultations', ['DOCTOR', 'NURSE']],
            'ordonnances' => ['prescriptions', ['DOCTOR', 'NURSE', 'PHARMACIST']],
            'résultats et demandes de laboratoire' => ['lab-requests', ['DOCTOR', 'NURSE', 'LABORATORY_TECHNICIAN']],
            'imagerie et comptes rendus' => ['imaging-requests', ['DOCTOR', 'RADIOLOGIST']],
            'catalogue des examens (labo)' => ['lab-exams', ['DOCTOR', 'NURSE', 'LABORATORY_TECHNICIAN', 'ADMIN_CLINIC']],
            'catalogue des examens (imagerie)' => ['imaging-exams', ['DOCTOR', 'RADIOLOGIST', 'ADMIN_CLINIC']],
            'certificats médicaux' => ['certificates', ['DOCTOR']],
            'factures et encaissements' => ['invoices', ['ADMIN_CLINIC', 'CASHIER']],
            'caisse' => ['cash/summary', ['ADMIN_CLINIC', 'CASHIER']],
            'stock pharmacie' => ['pharmacy/stock', ['PHARMACIST']],
            'dossiers du personnel' => ['hr/employees', ['ADMIN_CLINIC', 'HR_MANAGER']],
            'paie' => ['hr/payroll', ['ADMIN_CLINIC', 'HR_MANAGER']],
            'pointage du personnel' => ['hr/attendance', ['HR_MANAGER']],
            'comptes utilisateurs' => ['users', ['ADMIN_CLINIC']],
            'rapports d’activité' => ['reports/overview', ['ADMIN_CLINIC']],
        ];
    }

    #[DataProvider('policies')]
    public function test_only_the_roles_that_need_it_can_read_the_data(string $endpoint, array $allowed): void
    {
        $path = str_replace('{patient}', (string) $this->patient->id, $endpoint);

        foreach (self::STAFF as $role) {
            $response = $this->actingAs($this->user($role), 'sanctum')->getJson("/api/v1/{$path}");
            $expected = in_array($role, $allowed, true) ? 200 : 403;
            $this->assertSame($expected, $response->status(), "{$role} doit obtenir {$expected} sur {$endpoint}.");
        }
    }

    public function test_a_patient_account_reads_only_its_own_record(): void
    {
        $account = $this->user('PATIENT');
        $this->patient->update(['user_id' => $account->id]);
        $stranger = Patient::create([
            'clinic_id' => $this->clinic->id, 'patient_unique_id' => 'POL-2026-000002', 'first_name' => 'Autre', 'last_name' => 'Patient',
        ]);

        $this->actingAs($account, 'sanctum')->getJson("/api/v1/patients/{$stranger->id}")->assertNotFound();
        $this->actingAs($account, 'sanctum')->getJson("/api/v1/patients/{$stranger->id}/export")->assertForbidden();
        $this->actingAs($account, 'sanctum')->getJson("/api/v1/patients/{$stranger->id}/vitals")->assertClientError();
        $this->actingAs($account, 'sanctum')->getJson("/api/v1/patients/{$stranger->id}/clinical-profile")->assertClientError();
    }

    public function test_hr_staff_see_only_their_own_leave_and_payslips(): void
    {
        $nurse = $this->user('NURSE');
        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/me/staff-space')->assertOk();
        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/hr/employees')->assertForbidden();
        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/hr/payroll')->assertForbidden();
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
