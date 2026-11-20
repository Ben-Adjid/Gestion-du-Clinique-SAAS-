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

/** While online payment is off, no subscription payment can be started, and the provider webhook is closed. */
class OnlinePaymentsDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_online_payment_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('services.online_payments.enabled'));
    }

    public function test_checkout_and_declared_payments_answer_not_available_in_french(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Clinique Paiement', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'PAY',
            'subscription_plan' => 'BASIC', 'admin_email' => 'pay@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'BASIC')->firstOrFail(), 'MONTHLY', false);
        $admin = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/checkout', ['plan_code' => 'PRO'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Le paiement en ligne n’est pas disponible pour le moment. Réglez votre abonnement auprès de Clinique+.');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/payments', ['amount' => 50000])->assertNotFound();
    }

    public function test_the_provider_webhook_is_closed(): void
    {
        $this->postJson('/webhooks/flutterwave', ['event' => 'charge.completed'])->assertNotFound();
    }
}
