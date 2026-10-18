<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\LabExam;
use App\Models\LabRequest;
use App\Models\Patient;
use App\Models\Payroll;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase5WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $hr;
    private User $director;
    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 07:50:00'); // a Monday
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = $this->makeClinic('HRCLI', 'ENTERPRISE');
        $this->hr = $this->user('HR_MANAGER', $this->clinic);
        $this->director = $this->user('ADMIN_CLINIC', $this->clinic);
        $this->nurse = $this->user('NURSE', $this->clinic);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_staff_file_rules_and_termination(): void
    {
        $this->actingAs($this->hr, 'sanctum');
        $payload = [
            'user_id' => $this->nurse->id, 'first_name' => 'Hawa', 'last_name' => 'Djibrine', 'position' => 'Infirmière',
            'contract_type' => 'CDD', 'hire_date' => '2026-01-01', 'base_salary' => 260000, 'payment_method' => 'MOBILE_MONEY',
        ];
        $this->postJson('/api/v1/hr/employees', $payload)->assertUnprocessable()->assertJsonValidationErrors(['contract_end_date', 'payment_account']);
        $id = $this->postJson('/api/v1/hr/employees', [...$payload, 'contract_end_date' => '2026-12-31', 'payment_account' => '+235 66 00 11 22'])
            ->assertCreated()->assertJsonPath('data.employee_number', 'MAT-0001')->json('data.id');
        $this->postJson('/api/v1/hr/employees', [...$payload, 'contract_end_date' => '2026-12-31', 'payment_account' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->postJson("/api/v1/hr/employees/{$id}/terminate", ['end_date' => '2026-10-31', 'reason' => 'Fin de CDD'])->assertOk();
        $this->assertSame('INACTIVE', $this->nurse->fresh()->status);
        $this->patchJson("/api/v1/hr/employees/{$id}", ['position' => 'Autre'])->assertUnprocessable();

        // A nurse cannot open the staff files.
        $this->actingAs($this->user('NURSE', $this->clinic, 'other'), 'sanctum')->getJson('/api/v1/hr/employees')->assertForbidden();
    }

    public function test_self_service_clocking_marks_lateness(): void
    {
        $this->employee($this->nurse);
        $this->actingAs($this->nurse, 'sanctum');
        $this->postJson('/api/v1/me/attendance/clock')->assertOk()->assertJsonPath('data.status', 'PRESENT');
        Carbon::setTestNow('2026-10-12 17:30:00');
        $this->postJson('/api/v1/me/attendance/clock')->assertOk()->assertJsonPath('data.worked_hours', 9.67);
        $this->postJson('/api/v1/me/attendance/clock')->assertUnprocessable();

        Carbon::setTestNow('2026-10-13 08:40:00');
        $this->postJson('/api/v1/me/attendance/clock')->assertOk()->assertJsonPath('data.status', 'LATE');

        $this->actingAs($this->user('CASHIER', $this->clinic), 'sanctum')->postJson('/api/v1/me/attendance/clock')->assertUnprocessable();
    }

    public function test_leave_requests_follow_balance_overlap_and_review_rules(): void
    {
        $employee = $this->employee($this->nurse);
        $this->actingAs($this->nurse, 'sanctum');
        $leaveId = $this->postJson('/api/v1/hr/leaves', ['leave_type' => 'VACATION', 'start_date' => '2026-10-19', 'end_date' => '2026-10-24'])
            ->assertCreated()->assertJsonPath('data.days_count', 6)->json('data.id');
        $this->postJson('/api/v1/hr/leaves', ['leave_type' => 'VACATION', 'start_date' => '2026-10-22', 'end_date' => '2026-10-23'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/hr/leaves', ['leave_type' => 'VACATION', 'start_date' => '2026-11-02', 'end_date' => '2026-11-30'])
            ->assertUnprocessable()->assertJsonFragment(['message' => 'Solde insuffisant : 18 jour(s) de congé disponible(s), 25 demandé(s).']);
        $this->postJson('/api/v1/hr/leaves', ['leave_type' => 'SICK', 'start_date' => '2026-10-12', 'end_date' => '2026-10-12'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/hr/leaves/{$leaveId}/review", ['decision' => 'APPROVED'])->assertForbidden();

        $this->actingAs($this->hr, 'sanctum');
        $this->employee($this->hr, 'HR');
        $this->postJson("/api/v1/hr/leaves/{$leaveId}/review", ['decision' => 'REJECTED'])->assertUnprocessable();
        $this->postJson("/api/v1/hr/leaves/{$leaveId}/review", ['decision' => 'APPROVED'])->assertOk();
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->nurse->id, 'title' => 'Congé accepté']);
        $this->assertSame(18, $this->actingAs($this->nurse, 'sanctum')->getJson('/api/v1/me/staff-space')->json('data.leave_balance.remaining'));
        $this->postJson("/api/v1/hr/leaves/{$leaveId}/cancel")->assertForbidden();
        $this->assertSame($employee->id, Employee::where('user_id', $this->nurse->id)->value('id'));
    }

    public function test_payroll_is_calculated_approved_by_the_director_then_paid(): void
    {
        $employee = $this->employee($this->nurse, 'N', 260000);
        // September: one unpaid day of leave and one unjustified absence.
        \App\Models\Leave::create(['clinic_id' => $this->clinic->id, 'employee_id' => $employee->id, 'leave_type' => 'UNPAID', 'start_date' => '2026-09-14', 'end_date' => '2026-09-14', 'days_count' => 1, 'status' => 'APPROVED']);
        \App\Models\Attendance::create(['clinic_id' => $this->clinic->id, 'employee_id' => $employee->id, 'work_date' => '2026-09-30', 'status' => 'ABSENT']);
        \App\Models\Attendance::create(['clinic_id' => $this->clinic->id, 'employee_id' => $employee->id, 'work_date' => '2026-09-29', 'status' => 'PRESENT', 'check_in_datetime' => '2026-09-29 08:00:00', 'check_out_datetime' => '2026-09-29 19:00:00']);

        $this->actingAs($this->hr, 'sanctum');
        $this->postJson('/api/v1/hr/payroll/generate', ['month' => '2026-11'])->assertUnprocessable();
        $this->postJson('/api/v1/hr/payroll/generate', ['month' => '2026-09'])->assertOk()->assertJsonPath('data.created', 1);
        $payroll = Payroll::firstOrFail();
        // 260 000 / 26 = 10 000 per day → 2 unpaid days; 3 h overtime at 1.25 × 260 000 / 173.33.
        $this->assertSame(2.0, $payroll->unpaid_days);
        $this->assertSame(20000.0, $payroll->absence_deduction);
        $this->assertSame(3.0, $payroll->overtime_hours);
        $this->assertSame(5625.0, $payroll->overtime_amount);
        $this->assertSame(245625.0, $payroll->gross_salary);
        $this->assertSame(8597.0, $payroll->cotisation_deduction); // 3.5 %
        $this->assertSame(11851.0, $payroll->tax_deduction); // 5 % after CNPS
        $this->assertSame(225177.0, $payroll->net_salary);

        $this->patchJson("/api/v1/hr/payroll/{$payroll->id}", ['overtime_hours' => 3, 'bonus' => 15000, 'other_deductions' => 5000])->assertOk()->assertJsonPath('data.gross_salary', 260625);
        $this->postJson('/api/v1/hr/payroll/approve', ['payroll_ids' => [$payroll->id]])->assertForbidden();
        $this->actingAs($this->nurse, 'sanctum')->getJson("/api/v1/hr/payroll/{$payroll->id}")->assertNotFound();

        $this->actingAs($this->director, 'sanctum')->postJson('/api/v1/hr/payroll/approve', ['payroll_ids' => [$payroll->id]])->assertOk();
        $this->actingAs($this->hr, 'sanctum');
        $this->patchJson("/api/v1/hr/payroll/{$payroll->id}", ['overtime_hours' => 0, 'bonus' => 0, 'other_deductions' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/hr/payroll/generate', ['month' => '2026-09'])->assertJsonPath('data.locked', 1);
        $this->postJson("/api/v1/hr/payroll/{$payroll->id}/pay", ['payment_method' => 'MOBILE_MONEY'])->assertUnprocessable();
        $this->postJson("/api/v1/hr/payroll/{$payroll->id}/pay", ['payment_method' => 'MOBILE_MONEY', 'payment_reference' => 'AM-SAL-0926'])->assertOk()->assertJsonPath('data.status', 'PAID');

        $this->actingAs($this->nurse, 'sanctum')->getJson("/api/v1/hr/payroll/{$payroll->id}")->assertOk()->assertJsonPath('data.period_label', 'septembre 2026');
        $this->actingAs($this->user('CASHIER', $this->clinic), 'sanctum')->getJson("/api/v1/hr/payroll/{$payroll->id}")->assertNotFound();
    }

    public function test_hr_and_portal_require_the_enterprise_plan(): void
    {
        $basic = $this->makeClinic('BASICCLI', 'BASIC');
        $this->actingAs($this->user('HR_MANAGER', $basic), 'sanctum')
            ->getJson('/api/v1/hr/employees')->assertForbidden()->assertJsonPath('code', 'PLAN_MODULE_UNAVAILABLE');
        $modules = $this->actingAs($this->user('NURSE', $basic), 'sanctum')->getJson('/api/v1/me')->json('data.modules');
        $this->assertNotContains('staff', $modules);
    }

    public function test_patient_portal_only_shows_finalised_documents_of_the_patient(): void
    {
        $doctor = $this->user('DOCTOR', $this->clinic);
        $patientUser = $this->user('PATIENT', $this->clinic);
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'user_id' => $patientUser->id, 'patient_unique_id' => 'HRCLI-1', 'first_name' => 'Zara', 'last_name' => 'Idriss']);
        $other = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'HRCLI-2', 'first_name' => 'Ali', 'last_name' => 'Hassan']);
        $exam = LabExam::create(['clinic_id' => $this->clinic->id, 'exam_type' => 'HEMATOLOGY', 'name' => 'NFS', 'price' => 5000]);
        $make = function (Patient $owner, string $consultationStatus, string $labStatus) use ($doctor, $exam) {
            $consultation = Consultation::create(['clinic_id' => $this->clinic->id, 'patient_id' => $owner->id, 'doctor_id' => $doctor->id, 'consultation_datetime' => now(), 'status' => $consultationStatus]);
            $lab = LabRequest::create(['clinic_id' => $this->clinic->id, 'patient_id' => $owner->id, 'doctor_id' => $doctor->id, 'consultation_id' => $consultation->id, 'requested_at' => now(), 'status' => $labStatus]);
            $lab->exams()->sync([$exam->id]);

            return $lab;
        };
        $signedLab = $make($patient, 'SIGNED', 'SIGNED');
        $draftLab = $make($patient, 'DRAFT', 'IN_PROGRESS');
        $foreignLab = $make($other, 'SIGNED', 'SIGNED');
        Invoice::create(['clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'invoice_number' => 'HRCLI-1', 'subtotal' => 10000, 'total_amount' => 10000, 'status' => 'ISSUED', 'issued_at' => now(), 'due_date' => now()->addDays(3)]);
        Invoice::create(['clinic_id' => $this->clinic->id, 'patient_id' => $other->id, 'invoice_number' => 'HRCLI-2', 'subtotal' => 5000, 'total_amount' => 5000, 'status' => 'ISSUED', 'issued_at' => now(), 'due_date' => now()->addDays(3)]);

        $this->actingAs($patientUser, 'sanctum');
        $this->getJson('/api/v1/portal/summary')->assertOk()->assertJsonPath('data.counts.consultations', 1)->assertJsonPath('data.counts.lab', 1)->assertJsonPath('data.balance_due', 10000);
        $documents = collect($this->getJson('/api/v1/portal/documents')->assertOk()->json('data'));
        $this->assertSame(['CONSULTATION', 'LAB'], $documents->pluck('type')->sort()->values()->all());
        $this->getJson("/api/v1/portal/lab-requests/{$signedLab->id}")->assertOk();
        $this->getJson("/api/v1/portal/lab-requests/{$draftLab->id}")->assertNotFound();
        $this->getJson("/api/v1/portal/lab-requests/{$foreignLab->id}")->assertNotFound();
        $this->getJson('/api/v1/portal/invoices')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.outstanding_amount', 10000);

        // Staff accounts have no access to the portal.
        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/portal/summary')->assertForbidden();
    }

    private function employee(User $user, string $name = 'X', float $salary = 250000): Employee
    {
        static $sequence = 0;
        $sequence++;

        return Employee::create([
            'clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'employee_number' => 'MAT-9'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'first_name' => $name, 'last_name' => 'Test', 'position' => 'Poste', 'contract_type' => 'CDI',
            'base_salary' => $salary, 'hire_date' => '2025-01-01', 'status' => 'ACTIVE', 'payment_method' => 'CASH',
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

    private function makeClinic(string $code, string $planCode): Clinic
    {
        $clinic = Clinic::create([
            'name' => "Clinique {$code}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => $planCode, 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
        ClinicSubscription::create([
            'clinic_id' => $clinic->id,
            'plan_id' => SubscriptionPlan::where('code', $planCode)->value('id'),
            'status' => 'ACTIVE',
            'billing_cycle' => 'MONTHLY',
            'price' => 0,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        return $clinic;
    }
}
