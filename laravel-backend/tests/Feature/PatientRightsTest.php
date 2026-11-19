<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientConsent;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\ModuleAccess;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PatientRightsTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $director;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 09:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        [$this->clinic, $this->director] = $this->makeClinic('RGPD', 'ENTERPRISE');
        $this->patient = $this->makePatient('Halimé', 'Mahamat');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_consents_default_to_refused_and_can_be_recorded_by_staff(): void
    {
        $this->actingAs($this->director, 'sanctum')
            ->getJson("/api/v1/patients/{$this->patient->id}/rights")
            ->assertOk()
            ->assertJsonPath('data.consents.0.granted', false)
            ->assertJsonPath('data.has_portal_account', false);

        $this->actingAs($this->director, 'sanctum')
            ->putJson("/api/v1/patients/{$this->patient->id}/consents", ['consents' => ['SMS_REMINDERS' => true, 'STATISTICS' => false]])
            ->assertOk();

        $this->assertDatabaseHas('patient_consents', ['patient_id' => $this->patient->id, 'type' => 'SMS_REMINDERS', 'granted' => true, 'recorded_by' => $this->director->id]);
        $this->assertDatabaseHas('patient_consents', ['patient_id' => $this->patient->id, 'type' => 'STATISTICS', 'granted' => false]);
    }

    public function test_unknown_or_non_boolean_consents_are_refused(): void
    {
        $this->actingAs($this->director, 'sanctum')
            ->putJson("/api/v1/patients/{$this->patient->id}/consents", ['consents' => ['PUBLICITE' => true]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consents');

        $this->actingAs($this->director, 'sanctum')
            ->putJson("/api/v1/patients/{$this->patient->id}/consents", ['consents' => ['SMS_REMINDERS' => 'oui']])
            ->assertUnprocessable();

        $this->assertSame(0, PatientConsent::count());
    }

    public function test_staff_export_contains_the_record_and_is_a_download(): void
    {
        $this->patient->allergies()->create(['allergen' => 'Pénicilline', 'severity' => 'HIGH']);

        $response = $this->actingAs($this->user('NURSE', $this->clinic), 'sanctum')
            ->getJson("/api/v1/patients/{$this->patient->id}/export")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="dossier-'.$this->patient->patient_unique_id.'.json"')
            ->assertJsonPath('identity.first_name', 'Halimé')
            ->assertJsonPath('allergies.0.allergen', 'Pénicilline');

        $this->assertCount(count(PatientConsent::TYPES), $response->json('consents'));
    }

    public function test_only_staff_with_patient_access_can_export_and_patients_cannot_use_staff_routes(): void
    {
        $this->actingAs($this->user('PATIENT', $this->clinic), 'sanctum')
            ->getJson("/api/v1/patients/{$this->patient->id}/export")
            ->assertForbidden();
    }

    public function test_anonymisation_erases_identity_and_keeps_the_record_number_and_clinical_content(): void
    {
        $this->patient->emergencyContacts()->create(['emergency_contact_name' => 'Ali', 'emergency_contact_phone' => '+235 66 00 00 00', 'relationship' => 'Frère']);
        $this->patient->allergies()->create(['allergen' => 'Pénicilline', 'severity' => 'HIGH']);
        $number = $this->patient->patient_unique_id;

        $this->actingAs($this->director, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/anonymize", ['confirm' => 'ANONYMISER'])
            ->assertOk()
            ->assertJsonPath('data.anonymized_at', '2026-11-06T09:00:00+00:00');

        $fresh = $this->patient->fresh();
        $this->assertSame('Anonyme', $fresh->first_name);
        $this->assertSame('ANONYME', $fresh->last_name);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->date_of_birth);
        $this->assertSame($number, $fresh->patient_unique_id);
        $this->assertSame(0, $fresh->emergencyContacts()->count());
        $this->assertSame(1, $fresh->allergies()->count(), 'Les allergies, données médicales, sont conservées.');
    }

    public function test_anonymisation_needs_confirmation_cannot_repeat_and_refuses_linked_accounts(): void
    {
        $this->actingAs($this->director, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/anonymize", ['confirm' => 'oui'])
            ->assertUnprocessable();

        $this->actingAs($this->director, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/anonymize", ['confirm' => 'ANONYMISER'])
            ->assertOk();
        $this->actingAs($this->director, 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/anonymize", ['confirm' => 'ANONYMISER'])
            ->assertUnprocessable();

        $linked = $this->makePatient('Lié', 'Compte');
        $linked->update(['user_id' => $this->user('PATIENT', $this->clinic)->id]);
        $this->actingAs($this->director, 'sanctum')
            ->postJson("/api/v1/patients/{$linked->id}/anonymize", ['confirm' => 'ANONYMISER'])
            ->assertStatus(409);
        $this->assertSame('Lié', $linked->fresh()->first_name);
    }

    public function test_receptionist_cannot_anonymise_and_other_clinics_see_nothing(): void
    {
        $this->actingAs($this->user('RECEPTIONIST', $this->clinic), 'sanctum')
            ->postJson("/api/v1/patients/{$this->patient->id}/anonymize", ['confirm' => 'ANONYMISER'])
            ->assertForbidden();

        [$other, $otherDirector] = $this->makeClinic('AUTRE', 'ENTERPRISE');
        $this->actingAs($otherDirector, 'sanctum')
            ->getJson("/api/v1/patients/{$this->patient->id}/export")
            ->assertNotFound();
        $this->actingAs($otherDirector, 'sanctum')
            ->putJson("/api/v1/patients/{$this->patient->id}/consents", ['consents' => ['SMS_REMINDERS' => true]])
            ->assertNotFound();
    }

    public function test_patient_manages_own_consents_and_downloads_only_own_record(): void
    {
        $account = $this->user('PATIENT', $this->clinic);
        $this->patient->update(['user_id' => $account->id]);
        $this->makePatient('Autre', 'Patient');

        $this->actingAs($account, 'sanctum')
            ->getJson('/api/v1/portal/rights')
            ->assertOk()
            ->assertJsonPath('data.consents.0.granted', false);

        $this->actingAs($account, 'sanctum')
            ->putJson('/api/v1/portal/consents', ['consents' => ['EMAIL_COMMUNICATIONS' => true]])
            ->assertOk()
            ->assertJsonPath('data.consents.1.granted', true);

        $this->actingAs($account, 'sanctum')
            ->getJson('/api/v1/portal/export')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="mon-dossier-'.$this->patient->patient_unique_id.'.json"')
            ->assertJsonPath('identity.last_name', 'Mahamat')
            ->assertJsonMissing(['last_name' => 'Patient']);
    }

    public function test_patient_portal_refuses_changes_after_anonymisation(): void
    {
        $account = $this->user('PATIENT', $this->clinic);
        $this->patient->update(['user_id' => $account->id]);
        $this->patient->forceFill(['anonymized_at' => now()])->save();

        $this->actingAs($account, 'sanctum')
            ->putJson('/api/v1/portal/consents', ['consents' => ['SMS_REMINDERS' => true]])
            ->assertUnprocessable();
    }

    public function test_patient_account_does_not_see_the_staff_patient_list(): void
    {
        $account = $this->user('PATIENT', $this->clinic);
        $modules = ModuleAccess::forUser($account);

        $this->assertNotContains('patients', $modules);
        $this->assertContains('portal', $modules);
    }

    public function test_pharmacy_is_gated_by_the_plan_on_the_server(): void
    {
        $pharmacist = $this->user('PHARMACIST', $this->clinic);
        $this->actingAs($pharmacist, 'sanctum')->getJson('/api/v1/pharmacy/stock')->assertOk();

        SubscriptionPlan::where('code', 'ENTERPRISE')->update(['modules' => ['patients']]);
        // A fresh instance: the first request loaded the clinic and its plan on this user object.
        $this->actingAs(User::find($pharmacist->id), 'sanctum')->getJson('/api/v1/pharmacy/stock')->assertForbidden();
    }

    private function makePatient(string $first, string $last): Patient
    {
        return Patient::create([
            'clinic_id' => $this->clinic->id,
            'patient_unique_id' => 'RGPD-2026-'.str_pad((string) (Patient::withoutGlobalScopes()->count() + 1), 6, '0', STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
            'phone' => '+235 66 12 34 56',
            'email' => strtolower($first).'@example.test',
            'date_of_birth' => '1990-01-01',
            'gender' => 'F',
        ]);
    }

    private function user(string $role, Clinic $clinic): User
    {
        return User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    /** @return array{0: Clinic, 1: User} */
    private function makeClinic(string $code, string $plan): array
    {
        $clinic = Clinic::create([
            'name' => "Clinique {$code}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => $plan, 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', $plan)->firstOrFail(), 'MONTHLY', false);

        return [$clinic, $this->user('ADMIN_CLINIC', $clinic)];
    }
}
