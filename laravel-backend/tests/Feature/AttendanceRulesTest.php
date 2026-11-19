<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Clinic;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AbsenceAlerts;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceRulesTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $hr;
    private User $nurseUser;
    private Employee $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 08:30:00'); // a Monday
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Pointage', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'PTG',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'ptg@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->hr = $this->user('HR_MANAGER');
        $this->nurseUser = $this->user('NURSE');
        $this->nurse = Employee::create([
            'clinic_id' => $this->clinic->id, 'user_id' => $this->nurseUser->id, 'employee_number' => 'PTG-001',
            'first_name' => 'Halimé', 'last_name' => 'Infirmière', 'position' => 'Infirmière', 'contract_type' => 'CDI',
            'base_salary' => 150000, 'hire_date' => '2025-01-01', 'status' => 'ACTIVE', 'payment_method' => 'CASH',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_arrival_at_0830_is_recorded_late_by_thirty_minutes(): void
    {
        $this->actingAs($this->nurseUser, 'sanctum')->postJson('/api/v1/me/attendance/clock')->assertOk();

        $this->assertDatabaseHas('attendance', ['employee_id' => $this->nurse->id, 'status' => 'LATE', 'late_minutes' => 30]);
    }

    public function test_arrival_at_0800_is_on_time(): void
    {
        Carbon::setTestNow('2026-11-09 08:00:00');
        $this->actingAs($this->nurseUser, 'sanctum')->postJson('/api/v1/me/attendance/clock')->assertOk();

        $this->assertDatabaseHas('attendance', ['employee_id' => $this->nurse->id, 'status' => 'PRESENT', 'late_minutes' => 0]);
    }

    public function test_hr_manual_entry_derives_the_status_from_the_arrival_time(): void
    {
        $this->actingAs($this->hr, 'sanctum')->postJson('/api/v1/hr/attendance', [
            'employee_id' => $this->nurse->id, 'work_date' => '2026-11-06', 'status' => 'PRESENT', 'check_in' => '08:45',
        ])->assertOk();

        $this->assertDatabaseHas('attendance', ['employee_id' => $this->nurse->id, 'work_date' => '2026-11-06', 'status' => 'LATE', 'late_minutes' => 45]);
    }

    public function test_approved_justification_becomes_a_justified_absence_for_the_day(): void
    {
        $submitted = $this->actingAs($this->nurseUser, 'sanctum')->postJson('/api/v1/me/absence-requests', [
            'work_date' => '2026-11-06', 'reason' => 'Consultation médicale à l’hôpital de référence.',
        ])->assertCreated()->json('data');

        $this->actingAs($this->hr, 'sanctum')->postJson("/api/v1/hr/absence-requests/{$submitted['id']}/review", ['decision' => 'APPROVED'])->assertOk();

        // work_date is a datetime column: compare the day, not the time part.
        $this->assertTrue(Attendance::query()->where('employee_id', $this->nurse->id)->whereDate('work_date', '2026-11-06')
            ->where('status', 'JUSTIFIED_ABSENCE')->exists());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->nurseUser->id, 'type' => 'ABSENCE_REVIEW']);
    }

    public function test_a_rejection_needs_a_reason_and_a_day_with_a_clock_in_cannot_be_justified(): void
    {
        $submitted = $this->actingAs($this->nurseUser, 'sanctum')->postJson('/api/v1/me/absence-requests', [
            'work_date' => '2026-11-05', 'reason' => 'Absence pour raison familiale urgente.',
        ])->assertCreated()->json('data');

        $this->actingAs($this->hr, 'sanctum')->postJson("/api/v1/hr/absence-requests/{$submitted['id']}/review", ['decision' => 'REJECTED'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        Attendance::create(['clinic_id' => $this->clinic->id, 'employee_id' => $this->nurse->id, 'work_date' => '2026-11-05', 'check_in_datetime' => '2026-11-05 08:00:00', 'status' => 'PRESENT']);
        $this->actingAs($this->hr, 'sanctum')->postJson("/api/v1/hr/absence-requests/{$submitted['id']}/review", ['decision' => 'APPROVED'])
            ->assertUnprocessable();
    }

    public function test_staff_cannot_decide_their_own_justification(): void
    {
        $submitted = $this->actingAs($this->nurseUser, 'sanctum')->postJson('/api/v1/me/absence-requests', [
            'work_date' => '2026-11-06', 'reason' => 'Motif médical justifié par un certificat.',
        ])->assertCreated()->json('data');

        $this->actingAs($this->nurseUser, 'sanctum')->postJson("/api/v1/hr/absence-requests/{$submitted['id']}/review", ['decision' => 'APPROVED'])
            ->assertForbidden();
    }

    public function test_at_ten_the_missing_employees_are_reported_once_to_hr_only(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->artisan('attendance:alert-absences')->assertSuccessful();
        $this->artisan('attendance:alert-absences')->assertSuccessful();

        $alerts = UserNotification::query()->where('type', AbsenceAlerts::TYPE)->get();
        $this->assertCount(1, $alerts, 'Une seule alerte par personne et par jour, même si la commande tourne deux fois.');
        $this->assertSame($this->hr->id, $alerts->first()->user_id);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $this->nurseUser->id, 'type' => AbsenceAlerts::TYPE]);
    }

    public function test_no_alert_for_a_justified_or_clocked_day_nor_on_sundays(): void
    {
        Attendance::create(['clinic_id' => $this->clinic->id, 'employee_id' => $this->nurse->id, 'work_date' => '2026-11-09', 'check_in_datetime' => '2026-11-09 08:00:00', 'status' => 'PRESENT']);
        $this->assertSame(0, app(AbsenceAlerts::class)->run(Carbon::parse('2026-11-09'))['alerts']);

        Attendance::query()->delete();
        $this->assertSame(0, app(AbsenceAlerts::class)->run(Carbon::parse('2026-11-08'))['alerts'], 'Dimanche : pas de pointage attendu.');
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
