<?php

namespace Tests\Feature;

use App\Jobs\SendReceiptByEmail;
use App\Mail\ReceiptMail;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MedicalReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $cashier;
    private Patient $patient;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Hariri', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'HAR',
            'subscription_plan' => 'PRO', 'admin_email' => 'har@example.test', 'status' => 'ACTIVE',
            'settings' => ['address' => 'Avenue Charles de Gaulle', 'phone' => '+235 22 51 00 00', 'license_number' => 'AG-2026-042'],
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->cashier = $this->user('CASHIER');
        $this->patient = Patient::create([
            'clinic_id' => $this->clinic->id, 'patient_unique_id' => 'HAR-2026-000001', 'first_name' => 'Halimé', 'last_name' => 'Mahamat',
            'phone' => '+235 66 12 34 56', 'email' => 'halime@example.test',
        ]);
        $this->invoice = $this->invoice(30000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_payment_gets_a_sequential_receipt_number_per_clinic(): void
    {
        Queue::fake();
        $first = $this->pay(10000, 'CASH');
        $second = $this->pay(5000, 'CASH');

        $this->assertSame('REC-MED-2026-0001', $first->receipt_number);
        $this->assertSame('REC-MED-2026-0002', $second->receipt_number);
    }

    public function test_the_receipt_is_e_mailed_in_the_background_after_the_payment(): void
    {
        Queue::fake();
        $payment = $this->pay(10000, 'CASH');

        Queue::assertPushed(SendReceiptByEmail::class, fn (SendReceiptByEmail $job) => $job->paymentId === $payment->id);
        $this->assertSame(4, (new SendReceiptByEmail($payment->id))->tries);
    }

    public function test_the_patient_receives_the_receipt_as_a_pdf_attachment(): void
    {
        Mail::fake();
        $payment = $this->pay(30000, 'CASH');

        (new SendReceiptByEmail($payment->id))->handle();

        Mail::assertSent(ReceiptMail::class, fn (ReceiptMail $mail) => $mail->hasTo('halime@example.test'));
        $this->assertNotNull($payment->fresh()->receipt_sent_at);
    }

    public function test_a_patient_without_e_mail_is_skipped_without_error(): void
    {
        Mail::fake();
        $this->patient->update(['email' => null]);
        $payment = $this->pay(10000, 'CASH');

        (new SendReceiptByEmail($payment->id))->handle();

        Mail::assertNothingSent();
        $this->assertNull($payment->fresh()->receipt_sent_at);
    }

    public function test_the_receipt_pdf_is_light_and_shows_the_balance(): void
    {
        Queue::fake();
        $payment = $this->pay(10000, 'CASH');

        $response = $this->actingAs($this->cashier, 'sanctum')->get("/api/v1/payments/{$payment->id}/receipt")->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertLessThan(100 * 1024, strlen($response->getContent()), 'Le reçu doit peser moins de 100 Ko.');
        $figures = \App\Services\Receipts\ReceiptPdf::figures($payment);
        $this->assertFalse($figures['settled'], 'Un règlement partiel reste à payer.');
        $this->assertSame(20000.0, $figures['remaining']);
    }

    public function test_a_fully_settled_invoice_shows_soldé(): void
    {
        Queue::fake();
        $this->pay(10000, 'CASH');
        $last = $this->pay(20000, 'CASH');

        $figures = \App\Services\Receipts\ReceiptPdf::figures($last);
        $this->assertTrue($figures['settled'], 'Une facture entièrement réglée est soldée.');
        $this->assertSame(0.0, $figures['remaining']);
        $this->assertSame(30000.0, $figures['paidToDate']);
    }

    public function test_insurance_requires_the_insurer_and_wave_is_not_accepted_for_a_bill(): void
    {
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 10000, 'payment_method' => 'INSURANCE',
        ])->assertUnprocessable()->assertJsonValidationErrors('insurer_name');

        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 10000, 'payment_method' => 'WAVE',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 10000, 'payment_method' => 'BITCOIN',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    public function test_the_director_records_the_licence_number_printed_on_receipts(): void
    {
        $director = $this->user('ADMIN_CLINIC');

        $this->actingAs($director, 'sanctum')
            ->putJson("/api/v1/clinics/{$this->clinic->id}", ['license_number' => 'AG-2026-777'])
            ->assertOk();

        $this->assertSame('AG-2026-777', $this->clinic->fresh()->settings['license_number']);
        $this->assertSame('Avenue Charles de Gaulle', $this->clinic->fresh()->settings['address'], 'Les autres paramètres sont conservés.');
    }

    public function test_a_receipt_of_another_clinic_is_not_found(): void
    {
        Queue::fake();
        $payment = $this->pay(10000, 'CASH');
        $other = Clinic::create([
            'name' => 'Autre clinique', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AUC',
            'subscription_plan' => 'PRO', 'admin_email' => 'auc@example.test', 'status' => 'ACTIVE',
        ]);
        $outsider = User::factory()->create(['clinic_id' => $other->id, 'role_id' => Role::where('name', 'CASHIER')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($outsider, 'sanctum')->get("/api/v1/payments/{$payment->id}/receipt")->assertNotFound();
    }

    private function pay(float $amount, string $method, ?string $reference = null): Payment
    {
        $body = ['amount' => $amount, 'payment_method' => $method];
        if ($reference) {
            $body['payment_reference'] = $reference;
        }
        if ($method === 'INSURANCE') {
            $body['insurer_name'] = 'Assurance Tchad Vie';
        }
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $body)
            ->assertCreated();

        return Payment::query()->where('invoice_id', $this->invoice->id)->latest('id')->firstOrFail();
    }

    private function invoice(float $total): Invoice
    {
        $invoice = Invoice::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'invoice_number' => 'HAR-FAC-000001',
            'subtotal' => $total, 'tax_amount' => 0, 'tax_rate' => 0, 'total_amount' => $total, 'status' => 'ISSUED',
            'issued_at' => now(), 'due_date' => now()->addDays(7)->toDateString(), 'created_by' => $this->cashier->id,
        ]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Consultation générale', 'amount' => $total]);

        return $invoice;
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
