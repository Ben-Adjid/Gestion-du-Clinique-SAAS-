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

/** A patient account with no linked record (e.g. a patient who has no portal access) gets a clean French message. */
class PortalWithoutRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_patient_account_without_a_record_gets_a_clean_french_message(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Clinique Sans Dossier', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'NOR',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'nor@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $account = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'PATIENT')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($account, 'sanctum')
            ->getJson('/api/v1/portal/documents')
            ->assertNotFound()
            ->assertJsonPath('message', 'Élément introuvable ou non accessible.');
    }
}
