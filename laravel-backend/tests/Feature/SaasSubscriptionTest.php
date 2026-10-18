<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SaasInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SaasSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 09:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'clinic_id' => null,
            'role_id' => Role::where('name', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_super_admin_creates_a_clinic_with_its_administrator_and_a_trial(): void
    {
        Notification::fake();

        $response = $this->actingAs($this->superAdmin, 'sanctum')->postJson('/api/v1/saas/clinics', $this->clinicPayload())
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'TRIAL')
            ->assertJsonPath('data.subscription.trial_ends_at', '2026-03-15');

        $admin = User::where('email', 'directeur@hariri.td')->firstOrFail();
        $this->assertSame('ADMIN_CLINIC', $admin->role->name);
        $this->assertSame($response->json('data.id'), $admin->clinic_id);
        Notification::assertSentTo($admin, ResetPassword::class);
        $this->assertDatabaseCount('saas_invoices', 0);
        $this->assertDatabaseHas('audit_logs', ['resource' => 'CLINIC', 'action' => 'CREATE', 'clinic_id' => $response->json('data.id')]);
    }

    public function test_paid_start_issues_a_first_invoice_due_within_payment_terms(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/v1/saas/clinics', [...$this->clinicPayload(), 'with_trial' => false, 'plan_code' => 'PRO'])
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'ACTIVE')
            ->assertJsonPath('data.invoices.0.amount', 150000)
            ->assertJsonPath('data.invoices.0.status', 'PENDING')
            ->assertJsonPath('data.invoices.0.due_date', '2026-03-09');
    }

    public function test_end_of_trial_issues_the_first_invoice(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: true);

        app(SubscriptionService::class)->runBilling(Carbon::parse('2026-03-16'));

        $subscription = $clinic->subscription->fresh();
        $this->assertSame('ACTIVE', $subscription->status);
        $this->assertSame('2026-03-16', $subscription->current_period_start->toDateString());
        $this->assertSame(1, $subscription->invoices()->where('status', 'PENDING')->count());
    }

    public function test_unpaid_clinic_is_suspended_and_only_its_administrator_can_reach_the_subscription(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: false);
        $admin = $this->staff($clinic, 'ADMIN_CLINIC', 'admin@clinic.test');
        $this->staff($clinic, 'RECEPTIONIST', 'accueil@clinic.test');
        $service = app(SubscriptionService::class);

        $service->runBilling(Carbon::parse('2026-03-10'));
        $this->assertSame('PAST_DUE', $clinic->subscription->fresh()->status);
        $this->assertSame('ACTIVE', $clinic->fresh()->status);

        $service->runBilling(Carbon::parse('2026-03-17'));
        $this->assertSame('SUSPENDED', $clinic->subscription->fresh()->status);
        $this->assertSame('SUSPENDED', $clinic->fresh()->status);

        $this->postJson('/api/v1/auth/login', ['email' => 'accueil@clinic.test', 'password' => 'Password@12345'])->assertForbidden();
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@clinic.test', 'password' => 'Password@12345'])->assertOk();

        $admin = $admin->fresh();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/me')->assertJsonPath('data.modules', ['subscription', 'settings']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/patients')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.invoices.0.status', 'OVERDUE');
    }

    public function test_declared_mobile_money_payment_reactivates_the_clinic_once_confirmed(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: false);
        $admin = $this->staff($clinic, 'ADMIN_CLINIC', 'admin@clinic.test');
        app(SubscriptionService::class)->runBilling(Carbon::parse('2026-03-17'));
        $invoice = SaasInvoice::where('clinic_id', $clinic->id)->firstOrFail();

        $paymentId = $this->actingAs($admin->fresh(), 'sanctum')->postJson('/api/v1/subscription/payments', [
            'saas_invoice_id' => $invoice->id,
            'amount' => 50000,
            'method' => 'ORANGE_MONEY',
            'reference' => 'OM260317.1043.A12345',
            'paid_on' => '2026-03-02',
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING')->json('data.id');

        $this->assertSame('SUSPENDED', $clinic->fresh()->status);

        $this->actingAs($this->superAdmin, 'sanctum')->postJson("/api/v1/saas/payments/{$paymentId}/confirm")->assertOk();

        $this->assertSame('PAID', $invoice->fresh()->status);
        $this->assertSame('ACTIVE', $clinic->subscription->fresh()->status);
        $this->assertSame('ACTIVE', $clinic->fresh()->status);
    }

    public function test_rejected_payment_notifies_the_clinic_and_changes_nothing(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: false);
        $admin = $this->staff($clinic, 'ADMIN_CLINIC', 'admin@clinic.test');
        $invoice = SaasInvoice::where('clinic_id', $clinic->id)->firstOrFail();
        $paymentId = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/subscription/payments', [
            'saas_invoice_id' => $invoice->id, 'amount' => 50000, 'method' => 'MOOV_MONEY', 'reference' => 'MV-0001', 'paid_on' => '2026-03-02',
        ])->json('data.id');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/saas/payments/{$paymentId}/reject", ['reason' => 'Référence introuvable chez Moov'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');

        $this->assertSame('PENDING', $invoice->fresh()->status);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $admin->id, 'title' => 'Paiement d’abonnement refusé']);
    }

    public function test_payment_reference_cannot_be_reused_and_amount_cannot_exceed_balance(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: false);
        $invoice = SaasInvoice::where('clinic_id', $clinic->id)->firstOrFail();
        $payload = ['amount' => 20000, 'method' => 'AIRTEL_MONEY', 'reference' => 'AM-777', 'paid_on' => '2026-03-02'];

        $this->actingAs($this->superAdmin, 'sanctum')->postJson("/api/v1/saas/invoices/{$invoice->id}/payments", $payload)->assertCreated();
        $this->actingAs($this->superAdmin, 'sanctum')->postJson("/api/v1/saas/invoices/{$invoice->id}/payments", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/saas/invoices/{$invoice->id}/payments", [...$payload, 'reference' => 'AM-778', 'amount' => 40000])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame('PENDING', $invoice->fresh()->status);
    }

    public function test_downgrade_is_refused_when_the_clinic_exceeds_the_target_plan_limits(): void
    {
        $clinic = $this->clinicOnPlan('PRO', trial: false);
        SubscriptionPlan::where('code', 'BASIC')->update(['max_users' => 1]);
        $this->staff($clinic, 'DOCTOR', 'doc1@clinic.test');
        $this->staff($clinic, 'NURSE', 'nurse1@clinic.test');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/saas/clinics/{$clinic->id}/subscription/change-plan", ['plan_code' => 'BASIC', 'billing_cycle' => 'MONTHLY'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_code');

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/saas/clinics/{$clinic->id}/subscription/change-plan", ['plan_code' => 'ENTERPRISE', 'billing_cycle' => 'YEARLY'])
            ->assertOk()
            ->assertJsonPath('data.subscription.price', 4000000);
    }

    public function test_basic_plan_has_no_laboratory_module(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: false);
        $doctor = $this->staff($clinic, 'DOCTOR', 'doc@clinic.test');

        $this->assertNotContains('lab', $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/me')->json('data.modules'));
        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/lab-requests')
            ->assertForbidden()->assertJsonPath('code', 'PLAN_MODULE_UNAVAILABLE');
    }

    public function test_plan_user_limit_blocks_new_staff_accounts(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: false);
        SubscriptionPlan::where('code', 'BASIC')->update(['max_users' => 1]);
        $admin = $this->staff($clinic, 'ADMIN_CLINIC', 'admin@clinic.test');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Nouvelle infirmière',
            'email' => 'new@clinic.test',
            'password' => 'Password@12345',
            'role_id' => Role::where('name', 'NURSE')->value('id'),
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
    }

    public function test_super_admin_has_no_access_to_medical_records_and_clinics_have_no_access_to_saas(): void
    {
        $clinic = $this->clinicOnPlan('PRO', trial: false);
        Patient::create(['clinic_id' => $clinic->id, 'patient_unique_id' => 'P-1', 'first_name' => 'Awa', 'last_name' => 'Diop']);
        $admin = $this->staff($clinic, 'ADMIN_CLINIC', 'admin@clinic.test');

        $this->actingAs($this->superAdmin, 'sanctum')->withHeader('X-Clinic-ID', (string) $clinic->id)->getJson('/api/v1/patients')->assertForbidden();
        $this->actingAs($this->superAdmin, 'sanctum')->getJson('/api/v1/saas/dashboard')->assertOk()->assertJsonPath('data.mrr', 150000);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/saas/clinics')->assertForbidden();
    }

    public function test_cancelled_subscription_ends_at_the_end_of_the_period(): void
    {
        $clinic = $this->clinicOnPlan('BASIC', trial: false);

        $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson("/api/v1/saas/clinics/{$clinic->id}/subscription/cancel", ['reason' => 'Fermeture de la clinique'])
            ->assertOk()
            ->assertJsonPath('data.subscription.auto_renew', false);

        app(SubscriptionService::class)->runBilling(Carbon::parse('2026-04-05'));

        $this->assertSame('CANCELLED', $clinic->subscription->fresh()->status);
        $this->assertSame('INACTIVE', $clinic->fresh()->status);
        $this->assertSame(1, SaasInvoice::where('clinic_id', $clinic->id)->count());
    }

    private function clinicPayload(): array
    {
        return [
            'name' => 'Clinique Hariri',
            'code' => 'HARIRI',
            'city' => 'N’Djamena',
            'country' => 'Tchad',
            'address' => 'Avenue Charles de Gaulle, Moursal',
            'phone' => '+235 22 51 00 00',
            'plan_code' => 'BASIC',
            'billing_cycle' => 'MONTHLY',
            'with_trial' => true,
            'admin_name' => 'Dr Abakar Mahamat',
            'admin_email' => 'directeur@hariri.td',
            'admin_phone' => '+235 66 12 34 56',
        ];
    }

    private function clinicOnPlan(string $planCode, bool $trial): Clinic
    {
        $clinic = Clinic::create([
            'name' => "Clinique {$planCode}", 'city' => 'Moundou', 'country' => 'Tchad', 'code' => "C_{$planCode}",
            'subscription_plan' => $planCode, 'admin_email' => 'c@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', $planCode)->firstOrFail(), 'MONTHLY', $trial);

        return $clinic->fresh();
    }

    private function staff(Clinic $clinic, string $role, string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => 'Password@12345',
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }
}
