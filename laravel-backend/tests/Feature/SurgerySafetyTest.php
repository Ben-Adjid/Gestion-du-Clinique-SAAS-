<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Surgery;
use App\Models\SubscriptionPlan;
use App\Models\SurgicalConsent;
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

/** An operation starts only with the informed consent and the checklist; the invoice follows the normal flow. */
class SurgerySafetyTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $surgeon;
    private User $nurse;
    private User $cashier;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 08:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Bloc', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'BLC',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'blc@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->surgeon = $this->user('DOCTOR');
        $this->nurse = $this->user('NURSE');
        $this->cashier = $this->user('CASHIER');
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'BLC-2026-000001', 'first_name' => 'Ali', 'last_name' => 'Opéré', 'date_of_birth' => '1975-03-03']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_operation_cannot_start_without_consent_and_a_complete_checklist(): void
    {
        $surgery = $this->surgery();

        $this->actingAs($this->surgeon, 'sanctum')->patchJson("/api/v1/surgeries/{$surgery->id}", ['status' => 'IN_PROGRESS'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame('PLANNED', $surgery->fresh()->status);
    }

    public function test_the_consent_is_recorded_once_by_the_surgeon_and_encrypted_at_rest(): void
    {
        $surgery = $this->surgery();
        $text = 'Appendicectomie sous anesthésie générale : risques d’infection et de saignement, alternative le traitement médical.';

        $this->actingAs($this->surgeon, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/consent", [
            'signer_name' => 'Ali Opéré', 'signer_relation' => 'PATIENT', 'information_text' => $text,
        ])->assertCreated();

        $raw = DB::table('surgical_consents')->where('surgery_id', $surgery->id)->value('information_text');
        $this->assertNotSame($text, $raw, 'Le texte d’information est chiffré au repos.');
        $this->assertSame($text, SurgicalConsent::query()->where('surgery_id', $surgery->id)->value('information_text'));

        $this->actingAs($this->surgeon, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/consent", [
            'signer_name' => 'Ali Opéré', 'signer_relation' => 'PATIENT', 'information_text' => $text,
        ])->assertUnprocessable()->assertJsonValidationErrors('consent');

        $this->actingAs($this->nurse, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/consent", [
            'signer_name' => 'Ali Opéré', 'signer_relation' => 'PATIENT', 'information_text' => $text,
        ])->assertForbidden();
    }

    public function test_the_team_fills_the_checklist_and_the_operation_starts_once_everything_is_ready(): void
    {
        $surgery = $this->surgery();
        $this->actingAs($this->surgeon, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/consent", [
            'signer_name' => 'Ali Opéré', 'signer_relation' => 'PATIENT',
            'information_text' => 'Intervention expliquée : risques, durée, suites opératoires et alternatives possibles.',
        ])->assertCreated();

        $items = ['identity_confirmed' => true, 'site_marked' => true, 'allergies_checked' => true, 'fasting_confirmed' => true];
        $this->actingAs($this->surgeon, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/checklist", $items + ['anesthesia_reviewed' => false])
            ->assertOk();
        $this->actingAs($this->surgeon, 'sanctum')->patchJson("/api/v1/surgeries/{$surgery->id}", ['status' => 'IN_PROGRESS'])
            ->assertUnprocessable();

        $surgery->staff()->attach($this->nurse->id, ['role' => 'OPERATING_NURSE']); // the nurse is on the operating team
        $this->actingAs($this->nurse, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/checklist", $items + ['anesthesia_reviewed' => true])
            ->assertOk()->assertJsonPath('data.ready_to_start', true);
        $this->actingAs($this->surgeon, 'sanctum')->patchJson("/api/v1/surgeries/{$surgery->id}", ['status' => 'IN_PROGRESS'])
            ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    }

    public function test_a_checklist_from_outside_the_team_is_refused(): void
    {
        $surgery = $this->surgery();
        $outsider = $this->user('NURSE');

        $this->actingAs($outsider, 'sanctum')->putJson("/api/v1/surgeries/{$surgery->id}/checklist", [
            'identity_confirmed' => true, 'site_marked' => true, 'allergies_checked' => true, 'fasting_confirmed' => true, 'anesthesia_reviewed' => true,
        ])->assertForbidden()->assertJsonPath('message', 'Seule l’équipe opératoire peut remplir la check-list.');
    }

    public function test_the_operation_is_invoiced_once_through_the_normal_invoice_flow(): void
    {
        $surgery = $this->surgery();

        $response = $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/invoice", ['amount' => 150000])
            ->assertCreated();
        $this->assertNotNull($response->json('data.invoice_number'));
        $this->assertSame($response->json('data.invoice_id'), $surgery->fresh()->invoice_id);

        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/surgeries/{$surgery->id}/invoice", ['amount' => 150000])
            ->assertUnprocessable()->assertJsonValidationErrors('invoice');
    }

    public function test_the_estimated_duration_is_kept(): void
    {
        $surgery = $this->surgery(90);

        $this->actingAs($this->surgeon, 'sanctum')->getJson("/api/v1/surgeries/{$surgery->id}")
            ->assertOk()->assertJsonPath('data.estimated_minutes', 90);
    }

    private function surgery(?int $minutes = 60): Surgery
    {
        return Surgery::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'operation_type' => 'Appendicectomie',
            'scheduled_at' => Carbon::now()->addDay(), 'estimated_minutes' => $minutes, 'room' => 'Bloc 1', 'status' => 'PLANNED',
            'surgeon_id' => $this->surgeon->id, 'created_by' => $this->surgeon->id,
        ]);
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
