<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\PatientSpecialtyRecord;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\SubscriptionPlan;
use App\Models\SurgicalIndication;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** From indication to operation: a head of service validates, the anesthesia assesses, then scheduling. */
class SpecialtyCircuitTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 08:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Spécialités', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'SPE',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'spe@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'SPE-2026-000001', 'first_name' => 'Ali', 'last_name' => 'Chirurgie', 'date_of_birth' => '1970-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_operation_is_refused_until_the_head_of_the_specialty_validates_the_indication(): void
    {
        $surgeon = $this->doctor('CHIRURGIE_GENERALE');
        $indication = $this->indication($surgeon);

        $this->actingAs($surgeon, 'sanctum')->postJson('/api/v1/surgeries', $this->plan($indication, $surgeon))
            ->assertUnprocessable()->assertJsonValidationErrors('indication_id');
        $this->assertSame(0, DB::table('surgeries')->count());
        $this->assertSame('La validation du chef de service est obligatoire avant la programmation.', $this->lastError($indication, $surgeon));
    }

    public function test_only_the_head_of_the_same_specialty_decides_and_a_refusal_needs_a_reason(): void
    {
        $surgeon = $this->doctor('CHIRURGIE_GENERALE');
        $indication = $this->indication($surgeon);

        $this->actingAs($surgeon, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/decide", ['decision' => 'APPROVE'])
            ->assertForbidden()->assertJsonPath('message', 'Seul le chef de cette spécialité peut valider ou refuser cette indication.');

        $otherHead = $this->doctor('MEDECINE_INTERNE', head: true);
        $this->actingAs($otherHead, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/decide", ['decision' => 'APPROVE'])
            ->assertForbidden();

        $head = $this->doctor('CHIRURGIE_GENERALE', head: true);
        $this->actingAs($head, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/decide", ['decision' => 'REFUSE'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($head, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/decide", ['decision' => 'APPROVE'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_the_anesthesia_assesses_after_approval_and_the_operation_follows(): void
    {
        $surgeon = $this->doctor('CHIRURGIE_GENERALE');
        $indication = $this->indication($surgeon);
        $head = $this->doctor('CHIRURGIE_GENERALE', head: true);
        $anesthetist = $this->doctor('ANESTHESIE_REA');
        $general = $this->doctor('MEDECINE_INTERNE');

        $this->actingAs($anesthetist, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/pre-anesthesia", ['asa' => 2, 'allergies_reviewed' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'L’évaluation pré-anesthésique suit la validation du chef de service.');

        $this->actingAs($head, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/decide", ['decision' => 'APPROVE'])->assertOk();

        $this->actingAs($general, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/pre-anesthesia", ['asa' => 2, 'allergies_reviewed' => true])
            ->assertForbidden()->assertJsonPath('message', 'La consultation pré-anesthésique est réservée aux médecins anesthésistes-réanimateurs.');

        $this->actingAs($anesthetist, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/pre-anesthesia", ['asa' => 9, 'allergies_reviewed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('asa');
        $this->actingAs($anesthetist, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/pre-anesthesia", ['asa' => 2, 'allergies_reviewed' => true, 'contraindications' => 'Aucune'])
            ->assertOk()->assertJsonPath('data.ready_to_schedule', true);

        $this->actingAs($surgeon, 'sanctum')->postJson('/api/v1/surgeries', $this->plan($indication, $surgeon))
            ->assertCreated()->assertJsonPath('data.status', 'PLANNED');
        $this->assertSame($indication->id, DB::table('surgeries')->value('indication_id'));
    }

    public function test_the_anesthetist_must_be_an_anesthesia_doctor_and_the_surgeon_of_the_specialty(): void
    {
        $surgeon = $this->doctor('CHIRURGIE_GENERALE');
        $indication = $this->readyIndication($surgeon);
        $general = $this->doctor('MEDECINE_INTERNE');

        $this->actingAs($surgeon, 'sanctum')->postJson('/api/v1/surgeries', $this->plan($indication, $surgeon) + ['anesthetist_id' => $general->id])
            ->assertUnprocessable()->assertJsonValidationErrors('anesthetist_id');

        $wrongSurgeon = $this->doctor('MEDECINE_INTERNE');
        $this->actingAs($wrongSurgeon, 'sanctum')->postJson('/api/v1/surgeries', $this->plan($indication, $wrongSurgeon))
            ->assertUnprocessable()->assertJsonValidationErrors('surgeon_id');
    }

    public function test_a_refused_indication_cannot_be_scheduled(): void
    {
        $surgeon = $this->doctor('CHIRURGIE_GENERALE');
        $indication = $this->indication($surgeon);
        $head = $this->doctor('CHIRURGIE_GENERALE', head: true);
        $this->actingAs($head, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/decide", ['decision' => 'REFUSE', 'reason' => 'Bilan insuffisant'])->assertOk();

        $this->actingAs($surgeon, 'sanctum')->postJson('/api/v1/surgeries', $this->plan($indication, $surgeon))
            ->assertUnprocessable()->assertJsonValidationErrors('indication_id');
    }

    public function test_only_the_director_names_a_head_and_a_head_needs_a_specialty(): void
    {
        $doctor = $this->doctor('CHIRURGIE_GENERALE');
        $director = $this->user('ADMIN_CLINIC');
        $nurse = $this->user('NURSE');

        $this->actingAs($nurse, 'sanctum')->putJson("/api/v1/doctors/{$doctor->id}/head", ['is_head' => true])
            ->assertForbidden()->assertJsonPath('message', 'Seul le directeur de la clinique nomme les chefs de service.');
        $this->actingAs($director, 'sanctum')->putJson("/api/v1/doctors/{$doctor->id}/head", ['is_head' => true])
            ->assertOk()->assertJsonPath('data.is_head', true);
        $this->assertTrue(DoctorProfile::query()->where('user_id', $doctor->id)->value('is_head'));

        $bare = $this->user('DOCTOR');
        $this->actingAs($director, 'sanctum')->putJson("/api/v1/doctors/{$bare->id}/head", ['is_head' => true])->assertUnprocessable();
    }

    public function test_the_service_dashboard_is_for_the_head_only_and_stays_in_the_clinic(): void
    {
        $head = $this->doctor('CHIRURGIE_GENERALE', head: true);
        $this->actingAs($head, 'sanctum')->getJson('/api/v1/service/dashboard')->assertOk()
            ->assertJsonPath('data.specialty', 'Chirurgie générale')
            ->assertJsonStructure(['data' => ['beds', 'indications_to_validate', 'operations_late', 'operations', 'on_call_today']]);

        $member = $this->doctor('CHIRURGIE_GENERALE');
        $this->actingAs($member, 'sanctum')->getJson('/api/v1/service/dashboard')
            ->assertForbidden()->assertJsonPath('message', 'Ce tableau de bord est réservé au chef de service de la spécialité.');

        $other = Clinic::create(['name' => 'Autre clinique', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AUS', 'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'aus@example.test', 'status' => 'ACTIVE']);
        $outsider = User::factory()->create(['clinic_id' => $other->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);
        $indication = $this->indication($member);
        $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/indications/{$indication->id}/decide", ['decision' => 'APPROVE'])->assertNotFound();
    }

    public function test_a_dental_chart_and_an_eye_record_are_kept_encrypted_and_validated(): void
    {
        $dentist = $this->doctor('ODONTOLOGIE');
        $this->actingAs($dentist, 'sanctum')->postJson("/api/v1/patients/{$this->patient->id}/specialty-records", [
            'specialty_code' => 'ODONTOLOGIE', 'teeth' => ['16' => 'CARIE', '36' => 'SOIN', '48' => 'ABSENT'], 'note' => 'Caries sur 16, soin de la 36.',
        ])->assertCreated();
        $this->actingAs($dentist, 'sanctum')->postJson("/api/v1/patients/{$this->patient->id}/specialty-records", [
            'specialty_code' => 'ODONTOLOGIE', 'teeth' => ['99' => 'CARIE'],
        ])->assertUnprocessable()->assertJsonValidationErrors('teeth');

        $raw = DB::table('patient_specialty_records')->where('patient_id', $this->patient->id)->value('data');
        $this->assertStringNotContainsString('CARIE', (string) $raw, 'Le schéma dentaire est chiffré au repos.');
        $this->assertSame('CARIE', PatientSpecialtyRecord::query()->value('data')['teeth']['16']);

        $ophtalmo = $this->doctor('OPHTALMOLOGIE');
        $this->actingAs($ophtalmo, 'sanctum')->postJson("/api/v1/patients/{$this->patient->id}/specialty-records", [
            'specialty_code' => 'OPHTALMOLOGIE', 'acuite_od' => '10/10',
        ])->assertUnprocessable()->assertJsonValidationErrors('acuite_og');
        $this->actingAs($ophtalmo, 'sanctum')->postJson("/api/v1/patients/{$this->patient->id}/specialty-records", [
            'specialty_code' => 'OPHTALMOLOGIE', 'acuite_od' => '10/10', 'acuite_og' => '8/10', 'tension_od' => 16, 'tension_og' => 18,
        ])->assertCreated();
    }

    public function test_the_catalogue_lists_the_specialties_and_the_seeded_doctors_keep_theirs(): void
    {
        $user = $this->user('DOCTOR');
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/specialties')->assertOk();
        $this->assertSame(14, Specialty::query()->count());
        $this->assertSame('Ophtalmologie', Specialty::query()->where('code', 'OPHTALMOLOGIE')->value('label'));
    }

    /** A doctor of a specialty; the head flag is set only when asked. */
    private function doctor(string $code, bool $head = false): User
    {
        $user = $this->user('DOCTOR');
        $specialty = Specialty::query()->where('code', $code)->firstOrFail();
        DoctorProfile::query()->create(['clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'specialty' => $specialty->label, 'specialty_id' => $specialty->id, 'is_head' => $head]);

        return $user;
    }

    private function indication(User $surgeon): SurgicalIndication
    {
        return SurgicalIndication::query()->create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'requested_by' => $surgeon->id,
            'specialty_id' => $surgeon->doctorProfile->specialty_id, 'operation_type' => 'Appendicectomie', 'urgency' => 'URGENT',
            'motif' => 'Douleur de la fosse iliaque droite, fièvre, signe de Mac Burney.', 'status' => 'PENDING_HEAD',
        ]);
    }

    private function readyIndication(User $surgeon): SurgicalIndication
    {
        return SurgicalIndication::query()->create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'requested_by' => $surgeon->id,
            'specialty_id' => $surgeon->doctorProfile->specialty_id, 'operation_type' => 'Appendicectomie', 'urgency' => 'URGENT',
            'motif' => 'Douleur de la fosse iliaque droite, fièvre, signe de Mac Burney.', 'status' => 'APPROVED',
            'asa' => 2, 'allergies_reviewed' => true, 'pre_anesthesia_at' => now(), 'pre_anesthesia_by' => $this->doctor('ANESTHESIE_REA')->id,
        ]);
    }

    private function plan(SurgicalIndication $indication, User $surgeon): array
    {
        return [
            'patient_id' => $this->patient->id, 'indication_id' => $indication->id, 'operation_type' => 'Appendicectomie',
            'scheduled_at' => '2026-11-23 09:00', 'surgeon_id' => $surgeon->id, 'room' => 'Bloc 1',
        ];
    }

    private function lastError(SurgicalIndication $indication, User $surgeon): ?string
    {
        $response = $this->actingAs($surgeon, 'sanctum')->postJson('/api/v1/surgeries', $this->plan($indication, $surgeon));

        return $response->json('errors.indication_id.0');
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
