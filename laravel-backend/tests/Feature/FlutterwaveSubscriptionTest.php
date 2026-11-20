<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\FlutterwaveTransaction;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlutterwaveSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'test-secret-hash-1234';

    private Clinic $clinic;
    private User $director;
    private ClinicSubscription $subscription;
    private SaasInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        // The online payment is switched off by default: these tests exercise the code path itself.
        config(["services.online_payments.enabled" => true]);
        Carbon::setTestNow('2026-03-02 09:00:00');
        config([
            'services.flutterwave.secret_key' => 'FLWSECK_TEST-123',
            'services.flutterwave.secret_hash' => self::HASH,
            'services.flutterwave.base_url' => 'https://api.flutterwave.com/v3',
            'app.frontend_url' => 'https://app.example.test',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        [$this->clinic, $this->director] = $this->makeClinic('FLWCLI');
        $this->subscription = app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->invoice = SaasInvoice::where('clinic_id', $this->clinic->id)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_checkout_creates_a_flutterwave_payment_with_server_side_amount_and_tenant_meta(): void
    {
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/abc']])]);

        $response = $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/v1/subscription/checkout', ['saas_invoice_id' => $this->invoice->id, 'amount' => 1])
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://checkout.flutterwave.com/v3/hosted/pay/abc')
            ->assertJsonPath('data.amount', 150000);

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === 'https://api.flutterwave.com/v3/payments'
                && $request->hasHeader('Authorization', 'Bearer FLWSECK_TEST-123')
                && $request['amount'] == 150000
                && $request['currency'] === 'XAF'
                && $request['meta']['organization_id'] === $this->clinic->id
                && $request['meta']['user_id'] === $this->director->id
                && str_starts_with($request['redirect_url'], 'https://app.example.test/subscription');
        });
        $this->assertDatabaseHas('flutterwave_transactions', ['tx_ref' => $response->json('data.tx_ref'), 'status' => 'PENDING', 'amount' => 150000]);
    }

    public function test_only_the_director_can_pay_and_a_provider_outage_is_reported_cleanly(): void
    {
        $this->actingAs($this->user('CASHIER', $this->clinic), 'sanctum')
            ->postJson('/api/v1/subscription/checkout', ['saas_invoice_id' => $this->invoice->id])
            ->assertForbidden();

        Http::fake(['api.flutterwave.com/*' => Http::response(['status' => 'error', 'message' => 'Service unavailable'], 503)]);
        $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/v1/subscription/checkout', ['saas_invoice_id' => $this->invoice->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');
        $this->assertDatabaseHas('flutterwave_transactions', ['status' => 'FAILED']);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        Http::fake();
        $this->postJson('/api/webhooks/flutterwave', $this->event(1), ['verif-hash' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/webhooks/flutterwave', $this->event(1))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_verified_webhook_settles_the_invoice_once_and_reactivates_a_suspended_clinic(): void
    {
        $this->invoice->update(['status' => 'OVERDUE']);
        $this->subscription->update(['status' => 'SUSPENDED']);
        $this->clinic->update(['status' => 'SUSPENDED']);
        $transaction = $this->pendingTransaction();
        Http::fake(['api.flutterwave.com/v3/transactions/987654/verify' => Http::response($this->verification($transaction->tx_ref, 150000))]);

        $this->postJson('/api/webhooks/flutterwave', $this->event(987654), ['verif-hash' => self::HASH])
            ->assertOk()->assertExactJson(['status' => 'success']);
        // Flutterwave retries: the same transaction must not be applied twice.
        $this->postJson('/api/webhooks/flutterwave', $this->event(987654), ['verif-hash' => self::HASH])->assertOk();

        $this->assertSame('SUCCESSFUL', $transaction->fresh()->status);
        $this->assertSame(1, SaasPayment::where('method', 'FLUTTERWAVE')->count());
        $this->assertSame('PAID', $this->invoice->fresh()->status);
        $this->assertSame('ACTIVE', $this->subscription->fresh()->status);
        $this->assertSame('ACTIVE', $this->clinic->fresh()->status);
        $this->assertSame(987654, (int) $this->subscription->fresh()->flw_transaction_id);
        $this->assertSame('FLW-MOCK-REF', $this->subscription->fresh()->flw_ref);
    }

    public function test_a_tampered_amount_or_currency_is_never_applied(): void
    {
        $transaction = $this->pendingTransaction();
        Http::fake(['api.flutterwave.com/v3/transactions/555/verify' => Http::response($this->verification($transaction->tx_ref, 100))]);

        $this->postJson('/api/webhooks/flutterwave', $this->event(555), ['verif-hash' => self::HASH])->assertOk();

        $this->assertSame('FAILED', $transaction->fresh()->status);
        $this->assertSame('Montant reçu inférieur au montant attendu.', $transaction->fresh()->failure_reason);
        $this->assertSame('PENDING', $this->invoice->fresh()->status);
        $this->assertSame(0, SaasPayment::count());
    }

    public function test_a_renewal_buys_a_full_year_and_ends_the_trial(): void
    {
        $this->subscription->update(['status' => 'TRIAL', 'trial_ends_at' => '2026-03-10', 'current_period_end' => '2026-03-10']);
        $this->invoice->update(['status' => 'CANCELLED']);
        Http::fake([
            'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/pay/x']]),
            'api.flutterwave.com/v3/transactions/777/verify' => fn () => Http::response($this->verification(FlutterwaveTransaction::query()->withoutGlobalScopes()->latest('id')->value('tx_ref'), 1500000)),
        ]);

        $txRef = $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/v1/subscription/checkout', ['plan_code' => 'PRO', 'billing_cycle' => 'YEARLY'])
            ->assertCreated()->assertJsonPath('data.amount', 1500000)->json('data.tx_ref');
        // The customer comes back from Flutterwave before the webhook arrives.
        $this->getJson("/api/v1/subscription/checkout/verify?tx_ref={$txRef}&transaction_id=777")
            ->assertOk()->assertJsonPath('data.status', 'SUCCESSFUL');

        $subscription = $this->subscription->fresh();
        $this->assertSame('ACTIVE', $subscription->status);
        $this->assertSame('YEARLY', $subscription->billing_cycle);
        $this->assertSame('2026-03-02', $subscription->current_period_start->toDateString());
        $this->assertSame('2027-03-01', $subscription->current_period_end->toDateString());
        $this->assertDatabaseHas('saas_invoices', ['clinic_id' => $this->clinic->id, 'status' => 'PAID', 'amount' => 1500000, 'billing_cycle' => 'YEARLY']);
    }

    public function test_a_failed_payment_after_the_period_makes_the_subscription_past_due(): void
    {
        $this->subscription->update(['current_period_end' => '2026-02-28']);
        $transaction = $this->pendingTransaction();
        Http::fake(['api.flutterwave.com/v3/transactions/444/verify' => Http::response($this->verification($transaction->tx_ref, 150000, 'failed'))]);

        $this->postJson('/api/webhooks/flutterwave', $this->event(444), ['verif-hash' => self::HASH])->assertOk();

        $this->assertSame('FAILED', $transaction->fresh()->status);
        $this->assertSame('PAST_DUE', $this->subscription->fresh()->status);
    }

    public function test_a_clinic_cannot_follow_another_clinics_payment(): void
    {
        [$other, $otherDirector] = $this->makeClinic('OTHERFLW');
        $transaction = $this->pendingTransaction();
        $this->actingAs($otherDirector, 'sanctum')
            ->getJson("/api/v1/subscription/checkout/verify?tx_ref={$transaction->tx_ref}")
            ->assertNotFound();
        $this->assertNotNull($other->id);
    }

    public function test_expired_subscription_blocks_the_application_with_subscription_expired(): void
    {
        $nurse = $this->user('NURSE', $this->clinic);
        $nurse->update(['password' => bcrypt('Infirmiere@2026!')]);
        $this->clinic->update(['status' => 'SUSPENDED']);
        $this->subscription->update(['status' => 'SUSPENDED']);

        $this->actingAs($nurse, 'sanctum')->getJson('/api/v1/patients')
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED')
            ->assertJsonPath('can_pay', false);
        $this->actingAs($this->director, 'sanctum')->getJson('/api/v1/patients')
            ->assertForbidden()->assertJsonPath('can_pay', true);
        // The director keeps access to the subscription pages to pay.
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.online_payment_enabled', true);

        $this->postJson('/api/v1/auth/login', ['email' => $nurse->email, 'password' => 'Infirmiere@2026!'])
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
    }

    public function test_tenant_scope_falls_back_to_the_authenticated_users_clinic(): void
    {
        [$other] = $this->makeClinic('LEAKCHK');
        Patient::query()->withoutGlobalScopes()->create(['clinic_id' => $other->id, 'patient_unique_id' => 'LEAK-1', 'first_name' => 'Autre', 'last_name' => 'Clinique']);
        Patient::query()->withoutGlobalScopes()->create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'OWN-1', 'first_name' => 'Ma', 'last_name' => 'Clinique']);

        // No request context at all, only an authenticated user: the scope still applies.
        $this->actingAs($this->director);
        $this->assertSame(['OWN-1'], Patient::query()->pluck('patient_unique_id')->all());
        $this->expectException(\LogicException::class);
        Patient::create(['clinic_id' => $other->id, 'patient_unique_id' => 'LEAK-2', 'first_name' => 'X', 'last_name' => 'Y']);
    }

    private function pendingTransaction(): FlutterwaveTransaction
    {
        return FlutterwaveTransaction::query()->withoutGlobalScopes()->create([
            'clinic_id' => $this->clinic->id,
            'user_id' => $this->director->id,
            'subscription_id' => $this->subscription->id,
            'purpose' => 'INVOICE',
            'saas_invoice_id' => $this->invoice->id,
            'tx_ref' => 'CLQ-TEST-'.uniqid(),
            'amount' => 150000,
            'currency' => 'XAF',
            'status' => 'PENDING',
        ]);
    }

    private function event(int $id): array
    {
        return ['event' => 'charge.completed', 'data' => ['id' => $id, 'tx_ref' => 'ignored-by-the-server', 'amount' => 999999999, 'status' => 'successful']];
    }

    private function verification(string $txRef, float $amount, string $status = 'successful'): array
    {
        return ['status' => 'success', 'data' => [
            'id' => 1, 'tx_ref' => $txRef, 'flw_ref' => 'FLW-MOCK-REF', 'amount' => $amount, 'charged_amount' => $amount,
            'currency' => 'XAF', 'status' => $status, 'payment_type' => 'mobilemoneyfranco', 'processor_response' => 'Approved',
        ]];
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
    private function makeClinic(string $code): array
    {
        $clinic = Clinic::create([
            'name' => "Clinique {$code}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => 'PRO', 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);

        return [$clinic, $this->user('ADMIN_CLINIC', $clinic)];
    }
}
