<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Surgery;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DoctorPlanningSurgeryTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $receptionist;
    private User $cardiologist;
    private User $generalist;
    private User $anesthetist;
    private User $surgeon;
    private User $nurse;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 08:00:00'); // Monday
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Bloc', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'BLC',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'blc@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);

        $this->receptionist = $this->user('RECEPTIONIST');
        $this->cardiologist = $this->user('DOCTOR');
        $this->generalist = $this->user('DOCTOR');
        $this->anesthetist = $this->user('DOCTOR');
        $this->surgeon = $this->user('DOCTOR');
        $this->nurse = $this->user('NURSE');
        DoctorProfile::create(['clinic_id' => $this->clinic->id, 'user_id' => $this->cardiologist->id, 'specialty' => 'Cardiologie', 'employment' => 'CONSULTANT']);
        DoctorProfile::create(['clinic_id' => $this->clinic->id, 'user_id' => $this->anesthetist->id, 'specialty' => 'Anesthésie-réanimation', 'employment' => 'PERMANENT']);

        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'BLC-2026-000001', 'first_name' => 'Hawa', 'last_name' => 'Test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_doctor_not_on_the_published_planning_cannot_receive_an_appointment(): void
    {
        DoctorSchedule::create(['clinic_id' => $this->clinic->id, 'doctor_id' => $this->generalist->id, 'work_date' => '2026-11-10', 'status' => 'UNAVAILABLE']);
        DoctorSchedule::create(['clinic_id' => $this->clinic->id, 'doctor_id' => $this->cardiologist->id, 'work_date' => '2026-11-10', 'status' => 'PRESENT']);

        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/v1/appointments', $this->booking($this->generalist, '2026-11-10'))
            ->assertUnprocessable()->assertJsonValidationErrors('doctor_id');
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/v1/appointments', $this->booking($this->cardiologist, '2026-11-10'))
            ->assertCreated();
    }

    public function test_without_a_published_planning_for_the_day_booking_is_not_blocked(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/v1/appointments', $this->booking($this->generalist, '2026-11-12'))
            ->assertCreated();
    }

    public function test_the_doctor_list_is_filtered_by_specialty_and_shows_the_status_of_the_day(): void
    {
        DoctorSchedule::create(['clinic_id' => $this->clinic->id, 'doctor_id' => $this->cardiologist->id, 'work_date' => '2026-11-10', 'status' => 'ON_CALL']);

        $doctors = $this->actingAs($this->receptionist, 'sanctum')
            ->getJson('/api/v1/appointments/doctors?specialty=Cardiologie&date=2026-11-10')
            ->assertOk()->json('data');

        $this->assertCount(1, $doctors);
        $this->assertSame($this->cardiologist->id, $doctors[0]['id']);
        $this->assertSame('ON_CALL', $doctors[0]['planning_status']);
        $this->assertTrue($doctors[0]['bookable']);
    }

    public function test_a_booking_with_a_specialty_that_does_not_match_the_doctor_is_refused(): void
    {
        $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/v1/appointments', $this->booking($this->cardiologist, '2026-11-12') + ['specialty' => 'Pédiatrie'])
            ->assertUnprocessable();
    }

    public function test_the_board_lists_who_is_on_call_today_and_present_tomorrow(): void
    {
        DoctorSchedule::create(['clinic_id' => $this->clinic->id, 'doctor_id' => $this->cardiologist->id, 'work_date' => '2026-11-09', 'status' => 'ON_CALL']);
        DoctorSchedule::create(['clinic_id' => $this->clinic->id, 'doctor_id' => $this->generalist->id, 'work_date' => '2026-11-10', 'status' => 'PRESENT']);

        $board = $this->actingAs($this->receptionist, 'sanctum')->getJson('/api/v1/planning/board')->assertOk()->json('data');

        $this->assertSame($this->cardiologist->id, $board['today']['on_call'][0]['doctor_id']);
        $this->assertSame($this->generalist->id, $board['tomorrow']['present'][0]['doctor_id']);
    }

    public function test_the_doctor_registration_number_is_recorded_for_prescriptions(): void
    {
        $this->actingAs($this->user('ADMIN_CLINIC'), 'sanctum')
            ->putJson("/api/v1/doctors/{$this->generalist->id}/profile", [
                'specialty' => 'Médecine générale', 'employment' => 'PERMANENT', 'license_number' => 'ONM-2026-0412',
            ])->assertOk();

        $this->assertSame('ONM-2026-0412', $this->generalist->fresh()->doctorProfile->license_number);
    }

    public function test_only_the_administration_edits_the_planning(): void
    {
        $payload = ['entries' => [['doctor_id' => $this->generalist->id, 'work_date' => '2026-11-10', 'status' => 'PRESENT']]];

        $this->actingAs($this->receptionist, 'sanctum')->putJson('/api/v1/planning', $payload)->assertForbidden();
        $this->actingAs($this->user('ADMIN_CLINIC'), 'sanctum')->putJson('/api/v1/planning', $payload)->assertOk();
        $this->assertDatabaseHas('doctor_schedules', ['doctor_id' => $this->generalist->id, 'status' => 'PRESENT']);
    }

    public function test_only_the_assigned_surgeon_or_anesthetist_writes_the_operative_report(): void
    {
        $surgery = $this->surgery();
        $report = ['report' => 'Appendicectomie sous anesthésie générale, sans complication notable.'];

        $this->actingAs($this->generalist, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/report", $report)->assertForbidden();
        $this->actingAs($this->nurse, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/report", $report)->assertForbidden();
        $this->actingAs($this->surgeon(), 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/report", $report)->assertOk();
        $this->actingAs($this->anesthetist, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/report", $report)->assertOk();
    }

    public function test_the_signed_report_is_closed_and_only_the_surgeon_signs(): void
    {
        $surgery = $this->surgery();
        $surgeon = $this->surgeon();
        $this->actingAs($surgeon, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/report", ['report' => 'Compte rendu opératoire complet et détaillé.'])->assertOk();

        $this->actingAs($this->anesthetist, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/sign-report")->assertForbidden();
        // The operative report is signed with the surgeon's registration number.
        $this->actingAs($surgeon, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/sign-report")->assertUnprocessable()->assertJsonPath('code', 'LICENCE_REQUIRED');
        \App\Models\DoctorProfile::create(['clinic_id' => $this->clinic->id, 'user_id' => $surgeon->id, 'license_number' => 'ONM-2026-0500']);
        $surgeon->unsetRelation('doctorProfile'); // each request loads it afresh in production
        $this->actingAs($surgeon, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/sign-report")->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->actingAs($surgeon, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/report", ['report' => 'Tentative de modification après signature.'])
            ->assertUnprocessable();
    }

    public function test_the_report_is_visible_to_the_team_only_and_encrypted_at_rest(): void
    {
        $surgery = $this->surgery();
        $this->actingAs($this->surgeon(), 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/report", ['report' => 'Compte rendu confidentiel de l’intervention.'])->assertOk();

        $this->assertStringNotContainsString('confidentiel', (string) \Illuminate\Support\Facades\DB::table('surgeries')->where('id', $surgery->id)->value('report'));
        // A nurse who is not in the operating team does not see the report.
        $this->assertNull($this->actingAs($this->nurse, 'sanctum')->getJson("/api/v1/surgeries/{$surgery->id}")->json('data.report'));
        $surgery->staff()->attach($this->nurse->id, ['role' => 'OPERATING_NURSE']);
        $this->assertSame('Compte rendu confidentiel de l’intervention.', $this->actingAs($this->nurse, 'sanctum')
            ->getJson("/api/v1/surgeries/{$surgery->id}")->assertOk()->json('data.report'));
        $this->assertNull($this->actingAs($this->generalist, 'sanctum')->getJson("/api/v1/surgeries/{$surgery->id}")->json('data.report'));
        $this->actingAs($this->user('ADMIN_CLINIC'), 'sanctum')->getJson("/api/v1/surgeries/{$surgery->id}")->assertForbidden();
    }

    public function test_only_nurses_can_be_assigned_to_the_operating_team(): void
    {
        $indication = $this->readyIndication($this->surgeon());
        $this->actingAs($this->surgeon(), 'sanctum')->postJson('/api/v1/surgeries', [
            'patient_id' => $this->patient->id, 'indication_id' => $indication->id, 'operation_type' => 'Appendicectomie', 'scheduled_at' => '2026-11-12 09:00',
            'surgeon_id' => $this->surgeon()->id, 'staff' => [$this->generalist->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('staff');
    }

    /** An indication the head approved and the anesthesia assessed: the only kind an operation is scheduled from. */
    private function readyIndication(User $surgeon): \App\Models\SurgicalIndication
    {
        $specialty = \App\Models\Specialty::query()->where('code', 'CHIRURGIE_GENERALE')->firstOrFail();
        \App\Models\DoctorProfile::query()->updateOrCreate(['user_id' => $surgeon->id], ['clinic_id' => $this->clinic->id, 'specialty' => 'Chirurgie générale', 'specialty_id' => $specialty->id]);

        return \App\Models\SurgicalIndication::query()->create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'requested_by' => $surgeon->id, 'specialty_id' => $specialty->id,
            'operation_type' => 'Appendicectomie', 'urgency' => 'ELECTIVE', 'motif' => 'Appendicite aiguë confirmée à l’échographie, indication chirurgicale.',
            'status' => 'APPROVED', 'asa' => 2, 'allergies_reviewed' => true, 'pre_anesthesia_at' => now(), 'pre_anesthesia_by' => $this->generalist->id,
        ]);
    }

    public function test_the_operating_theatre_is_not_available_below_the_enterprise_plan(): void
    {
        SubscriptionPlan::where('code', 'ENTERPRISE')->update(['modules' => ['patients', 'appointments']]);
        $surgery = $this->surgery();
        $this->actingAs($this->surgeon(), 'sanctum')->getJson("/api/v1/surgeries/{$surgery->id}")->assertForbidden();
    }

    public function test_a_surgery_of_another_clinic_is_not_found(): void
    {
        $surgery = $this->surgery();
        $other = Clinic::create([
            'name' => 'Autre bloc', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'OTB',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'otb@example.test', 'status' => 'ACTIVE',
        ]);
        $outsider = User::factory()->create(['clinic_id' => $other->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);
        app(SubscriptionService::class)->start($other, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);

        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/surgeries/{$surgery->id}")->assertNotFound();
    }

    private function surgeon(): User
    {
        return $this->surgeon;
    }

    private function surgery(): Surgery
    {
        return Surgery::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'operation_type' => 'Appendicectomie',
            'scheduled_at' => '2026-11-12 09:00:00', 'status' => 'PLANNED', 'surgeon_id' => $this->surgeon()->id,
            'anesthetist_id' => $this->anesthetist->id, 'created_by' => $this->receptionist->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function booking(User $doctor, string $date): array
    {
        return [
            'patient_id' => $this->patient->id, 'doctor_id' => $doctor->id,
            'appointment_date' => $date, 'appointment_time' => '10:00', 'reason' => 'Consultation de suivi',
        ];
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
