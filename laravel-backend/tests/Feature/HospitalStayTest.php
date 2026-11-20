<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionTransfer;
use App\Models\AuditLog;
use App\Models\Bed;
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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Hospital stays: admission to a bed, search, transfer, discharge, and who may read the clinical file. */
class HospitalStayTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $nurse;
    private User $doctor;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 09:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Séjours', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'SEJ',
            'subscription_plan' => 'PRO', 'admin_email' => 'sej@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->nurse = $this->user('NURSE');
        $this->doctor = $this->user('DOCTOR');
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'SEJ-2026-000063', 'first_name' => 'Hawa', 'last_name' => 'Séjour', 'date_of_birth' => '1980-02-02']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admission_occupies_the_bed_and_opens_a_stay_under_the_doctor(): void
    {
        $bed = $this->bed('M-01');

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'reason' => 'Paludisme grave',
        ])->assertOk();

        $stay = Admission::query()->where('patient_id', $this->patient->id)->firstOrFail();
        $this->assertSame('ADMIS', $stay->status);
        $this->assertSame($bed->id, $stay->bed_id);
        $this->assertSame($this->doctor->id, $stay->doctor_id);
        $this->assertSame('OCCUPIED', $bed->fresh()->status);
    }

    public function test_a_patient_cannot_be_admitted_twice_nor_into_a_busy_bed(): void
    {
        $first = $this->bed('M-01');
        $second = $this->bed('M-02');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$first->id}/assign", ['patient_id' => $this->patient->id])->assertOk();

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$second->id}/assign", ['patient_id' => $this->patient->id])
            ->assertUnprocessable()->assertJsonValidationErrors('patient_id');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$first->id}/assign", ['patient_id' => $this->other()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('bed');
        $this->assertSame(1, Admission::query()->whereIn('status', Admission::ACTIVE)->count());
    }

    public function test_search_finds_a_hospitalised_patient_and_says_when_a_patient_is_not_admitted(): void
    {
        $bed = $this->bed('M-01');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id])->assertOk();

        $found = $this->actingAs($this->nurse, 'sanctum')->getJson('/api/v1/admissions/search?q=SEJ-2026-000063')->assertOk();
        $this->assertTrue($found->json('data.0.hospitalised'));
        $this->assertSame('M-01', $found->json('data.0.stay.bed'));
        $this->assertSame('MEDECINE', $found->json('data.0.stay.service'));

        $other = $this->other();
        $notAdmitted = $this->actingAs($this->nurse, 'sanctum')->getJson('/api/v1/admissions/search?q=Autre')->assertOk();
        $this->assertFalse($notAdmitted->json('data.0.hospitalised'));
        $this->assertSame('Ce patient n’est pas hospitalisé.', $notAdmitted->json('data.0.message'));
        $this->assertNotNull($other->id);
    }

    public function test_transfer_moves_the_stay_and_traces_both_beds(): void
    {
        $from = $this->bed('M-01');
        $to = $this->bed('M-02');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$from->id}/assign", ['patient_id' => $this->patient->id])->assertOk();
        $stay = Admission::query()->where('patient_id', $this->patient->id)->firstOrFail();

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/transfer", ['bed_id' => $to->id, 'reason' => 'Surveillance renforcée'])
            ->assertOk()->assertJsonPath('data.bed', 'M-02');

        $this->assertSame('TRANSFERE', $stay->fresh()->status);
        $this->assertSame('CLEANING', $from->fresh()->status);
        $this->assertSame('OCCUPIED', $to->fresh()->status);
        $move = AdmissionTransfer::query()->where('admission_id', $stay->id)->firstOrFail();
        $this->assertSame($from->id, $move->from_bed_id);
        $this->assertSame($to->id, $move->to_bed_id);
    }

    public function test_a_transfer_into_a_busy_bed_or_of_another_clinic_is_refused(): void
    {
        $from = $this->bed('M-01');
        $busy = $this->bed('M-02');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$from->id}/assign", ['patient_id' => $this->patient->id])->assertOk();
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$busy->id}/assign", ['patient_id' => $this->other()->id])->assertOk();
        $stay = Admission::query()->where('patient_id', $this->patient->id)->firstOrFail();

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/transfer", ['bed_id' => $busy->id])
            ->assertUnprocessable()->assertJsonValidationErrors('bed_id');

        $foreign = Clinic::create(['name' => 'Autre', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AUT', 'subscription_plan' => 'PRO', 'admin_email' => 'aut@example.test', 'status' => 'ACTIVE']);
        // Written directly: the tenant guard refuses to write another clinic's data through the model.
        $foreignBedId = \Illuminate\Support\Facades\DB::table('beds')->insertGetId(['clinic_id' => $foreign->id, 'code' => 'X-01', 'ward' => 'X', 'status' => 'AVAILABLE', 'created_at' => now(), 'updated_at' => now()]);
        $foreignBed = (object) ['id' => $foreignBedId];
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/transfer", ['bed_id' => $foreignBed->id])
            ->assertUnprocessable()->assertJsonValidationErrors('bed_id');
        $this->assertSame('ADMIS', $stay->fresh()->status, 'Une tentative refusée ne change pas le séjour.');
        $this->assertSame($from->id, $stay->fresh()->bed_id);
    }

    public function test_discharge_requires_a_summary_then_closes_the_stay_and_disinfects_the_bed(): void
    {
        $bed = $this->bed('M-01');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertOk();
        $stay = Admission::query()->where('patient_id', $this->patient->id)->firstOrFail();

        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/discharge", ['discharge_summary' => 'court'])
            ->assertUnprocessable()->assertJsonValidationErrors('discharge_summary');

        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/admissions/{$stay->id}/discharge", [
            'discharge_summary' => 'Apyrexie obtenue au 3e jour, traitement oral poursuivi à domicile.',
        ])->assertOk();

        $this->assertSame('SORTI', $stay->fresh()->status);
        $this->assertNotNull($stay->fresh()->discharged_at);
        $this->assertSame('Apyrexie obtenue au 3e jour, traitement oral poursuivi à domicile.', $stay->fresh()->discharge_summary);
        $this->assertSame('CLEANING', $bed->fresh()->status);
    }

    public function test_only_clinical_staff_opens_the_clinical_file_and_the_reason_is_hidden_from_administration(): void
    {
        $bed = $this->bed('M-01');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id, 'reason' => 'Motif médical confidentiel'])->assertOk();
        $stay = Admission::query()->where('patient_id', $this->patient->id)->firstOrFail();

        $this->actingAs($this->doctor, 'sanctum')->getJson("/api/v1/admissions/{$stay->id}")->assertOk()
            ->assertJsonPath('data.reason', 'Motif médical confidentiel');
        $reception = $this->user('RECEPTIONIST');
        $this->actingAs($reception, 'sanctum')->getJson("/api/v1/admissions/{$stay->id}")->assertForbidden()
            ->assertJsonPath('message', 'Le dossier de soins d’une hospitalisation est réservé au personnel soignant.');
        $this->actingAs($reception, 'sanctum')->getJson('/api/v1/admissions')->assertOk()
            ->assertJsonPath('data.0.reason', null);
    }

    public function test_opening_the_clinical_file_is_traced_in_the_audit(): void
    {
        $bed = $this->bed('M-01');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertOk();
        $stay = Admission::query()->where('patient_id', $this->patient->id)->firstOrFail();

        $this->actingAs($this->doctor, 'sanctum')->getJson("/api/v1/admissions/{$stay->id}")->assertOk();

        $this->assertTrue(AuditLog::query()->where('action', 'READ')->where('resource', 'ADMISSION')->where('resource_id', $stay->id)->exists());
    }

    public function test_a_stay_of_another_clinic_is_not_found(): void
    {
        $bed = $this->bed('M-01');
        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/beds/{$bed->id}/assign", ['patient_id' => $this->patient->id])->assertOk();
        $stay = Admission::query()->where('patient_id', $this->patient->id)->firstOrFail();

        $foreign = Clinic::create(['name' => 'Autre', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AUX', 'subscription_plan' => 'PRO', 'admin_email' => 'aux@example.test', 'status' => 'ACTIVE']);
        $outsider = User::factory()->create(['clinic_id' => $foreign->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/admissions/{$stay->id}")->assertNotFound();
    }

    /** A bed in the medicine room of the clinic, as the bed screen creates it. */
    private function bed(string $code): Bed
    {
        $room = \App\Models\Room::query()->firstOrCreate(['clinic_id' => $this->clinic->id, 'name' => 'Médecine interne'], ['service' => 'MEDECINE', 'capacity' => 1]);

        return Bed::query()->create(['clinic_id' => $this->clinic->id, 'room_id' => $room->id, 'code' => $code, 'ward' => 'Médecine interne', 'status' => 'AVAILABLE']);
    }

    private function other(): Patient
    {
        return Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'SEJ-2026-'.str_pad((string) random_int(100, 999999), 6, '0', STR_PAD_LEFT), 'first_name' => 'Autre', 'last_name' => 'Patient', 'date_of_birth' => '1990-01-01']);
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
