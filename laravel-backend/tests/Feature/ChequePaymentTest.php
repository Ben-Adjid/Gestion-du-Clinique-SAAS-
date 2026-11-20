<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
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

/** A clinic takes cash and cheques only; every cheque is registered and can change state once. */
class ChequePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => 'Clinique Chèque', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'CHQ',
            'subscription_plan' => 'PRO', 'admin_email' => 'chq@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->cashier = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'CASHIER')->value('id'), 'status' => 'ACTIVE']);
        $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_unique_id' => 'CHQ-2026-000001', 'first_name' => 'Hassan', 'last_name' => 'Test']);
        $this->invoice = Invoice::create([
            'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'invoice_number' => 'CHQ-FAC-000001',
            'subtotal' => 17000, 'tax_amount' => 0, 'tax_rate' => 0, 'total_amount' => 17000, 'status' => 'ISSUED',
            'issued_at' => now(), 'due_date' => now()->addDays(7)->toDateString(), 'created_by' => $this->cashier->id,
        ]);
        InvoiceItem::create(['invoice_id' => $this->invoice->id, 'description' => 'Consultation', 'amount' => 17000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cheque(array $overrides = []): array
    {
        return array_merge([
            'amount' => 5000,
            'payment_method' => 'CHEQUE',
            'cheque_number' => ' 0004521',
            'cheque_bank' => 'Ecobank Tchad',
            'cheque_drawer' => 'Hassan Brahim',
            'cheque_date' => '2026-11-18',
        ], $overrides);
    }

    public function test_cash_is_accepted_for_a_clinic_bill(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", ['amount' => 1000, 'payment_method' => 'CASH'])
            ->assertCreated();
    }

    public function test_mobile_money_and_card_are_refused_for_a_clinic_bill(): void
    {
        foreach (['WAVE', 'ORANGE_MONEY', 'MOBILE_MONEY', 'CARD', 'BANK_TRANSFER'] as $method) {
            $this->actingAs($this->cashier, 'sanctum')
                ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", ['amount' => 1000, 'payment_method' => $method, 'payment_reference' => 'REF-1'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('payment_method');
        }
    }

    public function test_a_cheque_needs_its_number_bank_drawer_and_date(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $this->cheque(['cheque_number' => '', 'cheque_bank' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cheque_number', 'cheque_bank']);
    }

    public function test_a_cheque_dated_in_the_future_is_refused(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $this->cheque(['cheque_date' => '2026-12-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cheque_date');
    }

    public function test_a_cheque_is_registered_as_pending_with_its_details(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $this->cheque())
            ->assertCreated();

        $payment = Payment::findOrFail($response->json('data.id'));
        $this->assertSame('CHEQUE', $payment->payment_method);
        $this->assertSame('PENDING', $payment->cheque_status);
        $this->assertSame('0004521', $payment->cheque_number);
        $this->assertSame('Ecobank Tchad', $payment->cheque_bank);
        $this->assertSame('Hassan Brahim', $payment->cheque_drawer);
    }

    public function test_the_register_lists_pending_cheques_with_totals(): void
    {
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $this->cheque())->assertCreated();

        $this->actingAs($this->cashier, 'sanctum')->getJson('/api/v1/cheques?status=PENDING')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('totals.pending_count', 1)
            ->assertJsonPath('totals.pending_amount', 5000);
    }

    public function test_a_cheque_is_cashed_once_and_then_locked(): void
    {
        $payment = $this->chequePayment();

        $this->actingAs($this->cashier, 'sanctum')
            ->patchJson("/api/v1/cheques/{$payment->id}/status", ['status' => 'CASHED'])
            ->assertOk();
        $this->assertSame('CASHED', $payment->fresh()->cheque_status);
        $this->assertSame($this->cashier->id, $payment->fresh()->cheque_status_changed_by);

        $this->actingAs($this->cashier, 'sanctum')
            ->patchJson("/api/v1/cheques/{$payment->id}/status", ['status' => 'REJECTED', 'note' => 'Provision insuffisante'])
            ->assertStatus(422);
        $this->assertSame('CASHED', $payment->fresh()->cheque_status);
    }

    public function test_a_rejected_cheque_needs_a_reason(): void
    {
        $payment = $this->chequePayment();

        $this->actingAs($this->cashier, 'sanctum')
            ->patchJson("/api/v1/cheques/{$payment->id}/status", ['status' => 'REJECTED'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_only_cheques_have_a_cheque_status(): void
    {
        $cash = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", ['amount' => 1000, 'payment_method' => 'CASH'])
            ->assertCreated();

        $this->actingAs($this->cashier, 'sanctum')
            ->patchJson('/api/v1/cheques/'.$cash->json('data.id').'/status', ['status' => 'CASHED'])
            ->assertStatus(422);
    }

    private function chequePayment(): Payment
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $this->cheque())
            ->assertCreated();

        return Payment::findOrFail($response->json('data.id'));
    }
}
