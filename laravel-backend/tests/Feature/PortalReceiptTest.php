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

/** A patient downloads the receipts of his own confirmed payments from the portal, and no one else's. */
class PortalReceiptTest extends TestCase
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

    public function test_a_patient_downloads_the_receipt_of_his_own_payment_only(): void
    {
        $clinic = Clinic::create([
            'name' => 'Clinique Portail', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'PTL',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'ptl@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $cashier = $this->user($clinic, 'CASHIER');
        $patientUser = $this->user($clinic, 'PATIENT');
        $patient = Patient::create(['clinic_id' => $clinic->id, 'user_id' => $patientUser->id, 'patient_unique_id' => 'PTL-2026-000001', 'first_name' => 'Hawa', 'last_name' => 'Test', 'date_of_birth' => '1985-07-07']);
        $other = Patient::create(['clinic_id' => $clinic->id, 'patient_unique_id' => 'PTL-2026-000002', 'first_name' => 'Autre', 'last_name' => 'Patient', 'date_of_birth' => '1990-01-01']);

        $ownPayment = $this->payment($clinic, $cashier, $patient, 'REC-MED-2026-9001');
        $otherPayment = $this->payment($clinic, $cashier, $other, 'REC-MED-2026-9002');

        $pdf = $this->actingAs($patientUser, 'sanctum')->get("/api/v1/portal/payments/{$ownPayment->id}/receipt")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertLessThan(100 * 1024, strlen($pdf->getContent()));

        $this->actingAs($patientUser, 'sanctum')->get("/api/v1/portal/payments/{$otherPayment->id}/receipt")->assertNotFound();

        $this->actingAs($patientUser, 'sanctum')->getJson('/api/v1/portal/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.payments.0.receipt_number', 'REC-MED-2026-9001');
    }

    private function payment(Clinic $clinic, User $cashier, Patient $patient, string $receipt): Payment
    {
        $invoice = Invoice::create([
            'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'invoice_number' => 'PTL-FAC-'.$receipt,
            'subtotal' => 15000, 'tax_amount' => 0, 'tax_rate' => 0, 'total_amount' => 15000, 'status' => 'PAID',
            'issued_at' => now(), 'due_date' => now()->addDays(7)->toDateString(), 'created_by' => $cashier->id,
        ]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Consultation générale', 'amount' => 15000]);

        return Payment::create([
            'clinic_id' => $clinic->id, 'invoice_id' => $invoice->id, 'amount' => 15000, 'payment_method' => 'CASH',
            'status' => 'CONFIRMED', 'received_by' => $cashier->id, 'payment_datetime' => now(), 'receipt_number' => $receipt,
        ]);
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
