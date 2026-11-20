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
use Tests\TestCase;

/** Possible duplicate: same phone and same birth date (or same name and birth date). A same name alone is not enough. */
class DuplicateRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Clinique Doublons', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'DUP',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'dup@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->reception = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'RECEPTIONIST')->value('id'), 'status' => 'ACTIVE']);
        Patient::create([
            'clinic_id' => $clinic->id, 'patient_unique_id' => 'DUP-2026-000001', 'first_name' => 'Fatouma', 'last_name' => 'Abakar',
            'date_of_birth' => '1990-05-05', 'phone' => '+235 66 11 22 33', 'gender' => 'F',
        ]);
    }

    public function test_the_same_name_alone_does_not_block_a_new_patient(): void
    {
        $this->actingAs($this->reception, 'sanctum')->postJson('/api/v1/patients', [
            'first_name' => 'Fatouma', 'last_name' => 'Abakar', 'gender' => 'F',
            'date_of_birth' => '1975-01-01', 'phone' => '+235 66 99 88 77',
        ])->assertCreated();
    }

    public function test_the_same_phone_and_birth_date_is_flagged_as_possible_duplicate_in_french(): void
    {
        $this->actingAs($this->reception, 'sanctum')->postJson('/api/v1/patients', [
            'first_name' => 'Fatouma', 'last_name' => 'Abakar', 'gender' => 'F',
            'date_of_birth' => '1990-05-05', 'phone' => '+235 66 11 22 33',
        ])->assertStatus(409)->assertJsonPath('code', 'POSSIBLE_DUPLICATE');
    }
}
