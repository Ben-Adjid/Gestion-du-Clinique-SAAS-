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

/** A clinic administrator invites a member of their own clinic, whatever the form sends in clinic_id. */
class UserInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clinic_administrator_creates_a_user_in_their_own_clinic_even_with_an_empty_clinic_field(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Clinique Invitation', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'INV',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'inv@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $admin = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Membre Test', 'email' => 'membre.test@example.test', 'phone' => '+235 66 00 00 09',
            'password' => 'Membre-Test-2026!x', 'role_id' => Role::where('name', 'NURSE')->value('id'), 'clinic_id' => '',
        ])->assertCreated();

        $this->assertSame($clinic->id, User::find($response->json('data.id') ?? $response->json('id'))?->clinic_id ?? $clinic->id);
        $this->assertSame($clinic->id, User::where('email', 'membre.test@example.test')->value('clinic_id'));
    }
}
