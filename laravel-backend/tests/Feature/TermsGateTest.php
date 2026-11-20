<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The clinic records are closed until the director accepts the current version of the terms of use. */
class TermsGateTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clinic.terms_enforced' => true, 'clinic.terms_version' => '2026-11']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique CGU', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'CGU',
            'subscription_plan' => 'PRO', 'admin_email' => 'cgu@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
    }

    public function test_the_records_stay_closed_until_the_director_accepts_the_terms(): void
    {
        $nurse = $this->user('NURSE');
        $director = $this->user('ADMIN_CLINIC');

        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/patients')
            ->assertForbidden()
            ->assertJsonPath('code', 'TERMS_REQUIRED')
            ->assertJsonPath('can_accept', false);
        $this->actingAs($director, 'sanctum')->getJson('/api/v1/patients')
            ->assertForbidden()->assertJsonPath('can_accept', true);

        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/terms', ['terms_version' => '2026-11', 'accepted' => true])
            ->assertOk()->assertJsonPath('data.terms_version', '2026-11');

        $nurse->unsetRelation('clinic'); // each request loads the clinic afresh in production
        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/patients')->assertOk();
    }

    public function test_a_new_version_of_the_terms_must_be_accepted_again(): void
    {
        $nurse = $this->user('NURSE');
        $director = $this->user('ADMIN_CLINIC');
        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/terms', ['terms_version' => '2026-11', 'accepted' => true])->assertOk();
        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/patients')->assertOk();

        config(['clinic.terms_version' => '2027-01']);

        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/patients')->assertForbidden()->assertJsonPath('code', 'TERMS_REQUIRED');
        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/terms', ['terms_version' => '2026-11', 'accepted' => true])
            ->assertUnprocessable();
    }

    public function test_only_the_director_accepts_and_the_platform_sees_the_version_and_date(): void
    {
        $nurse = $this->user('NURSE');
        $this->actingAs($nurse, 'sanctum')->postJson('/api/v1/clinic/terms', ['terms_version' => '2026-11', 'accepted' => true])
            ->assertForbidden()->assertJsonPath('message', 'Seul le directeur de la clinique peut accepter les conditions générales.');

        $director = $this->user('ADMIN_CLINIC');
        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/terms', ['terms_version' => '2026-11', 'accepted' => true])->assertOk();

        $superAdmin = $this->user('SUPER_ADMIN');
        $row = collect($this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/saas/clinics')->json('data'))->firstWhere('id', $this->clinic->id);
        $this->assertSame('2026-11', $row['terms_version']);
        $this->assertNotNull($row['terms_accepted_at']);
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
