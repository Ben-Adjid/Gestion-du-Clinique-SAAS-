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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Error responses reach the user in French, without framework text, model names or stack traces. */
class FrenchErrorMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_unknown_record_is_a_french_404_without_the_model_name(): void
    {
        $clinic = $this->clinic();
        $director = $this->user($clinic, 'ADMIN_CLINIC');

        $response = $this->actingAs($director, 'sanctum')->getJson('/api/v1/invoices/999999')->assertNotFound();

        $response->assertJsonPath('message', 'Élément introuvable ou non accessible.');
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
        $this->assertStringNotContainsString('No query results', $response->getContent());
    }

    public function test_a_forbidden_action_is_explained_in_french(): void
    {
        $clinic = $this->clinic();
        $patientUser = $this->user($clinic, 'PATIENT');

        $this->actingAs($patientUser, 'sanctum')->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous n’avez pas l’autorisation d’effectuer cette action.');
    }

    private function clinic(): Clinic
    {
        $clinic = Clinic::create([
            'name' => 'Clinique Français', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'FRN',
            'subscription_plan' => 'PRO', 'admin_email' => 'frn@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);

        return $clinic;
    }

    private function user(Clinic $clinic, string $role): User
    {
        return User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }
}
