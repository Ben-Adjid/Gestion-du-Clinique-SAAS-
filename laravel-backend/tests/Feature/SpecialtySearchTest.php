<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientSpecialtyRecord;
use App\Models\Role;
use App\Models\Specialty;
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

/** Patient search filtered by specialty: a patient with a record of that specialty is found under it. */
class SpecialtySearchTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Recherche', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'SRC',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'src@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->admin = User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_dental_patient_is_found_under_odontology_and_not_under_ophthalmology(): void
    {
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'SRC-2026-000001', 'first_name' => 'Ousmane', 'last_name' => 'Dentaire', 'date_of_birth' => '1988-03-12']);
        PatientSpecialtyRecord::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id,
            'specialty_id' => Specialty::where('code', 'ODONTOLOGIE')->value('id'),
            'data' => ['teeth' => ['16' => 'CARIE']], 'recorded_by' => $this->admin->id, 'recorded_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admissions/search?q=Dentaire&specialty=ODONTOLOGIE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient.patient_unique_id', 'SRC-2026-000001');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admissions/search?q=Dentaire&specialty=OPHTALMOLOGIE')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_unknown_specialty_is_refused_in_french(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admissions/search?q=Test&specialty=INCONNUE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['specialty']);
    }
}
