<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Invoice;
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

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 12:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Finance', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'FIN',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'fin@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'FIN-2026-000001', 'first_name' => 'Ali', 'last_name' => 'Test']);
        $this->invoice = Invoice::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'invoice_number' => 'FIN-FAC-000001',
            'subtotal' => 100000, 'tax_amount' => 0, 'tax_rate' => 0, 'total_amount' => 100000, 'status' => 'ISSUED',
            'issued_at' => now(), 'due_date' => now()->addDays(7)->toDateString(), 'created_by' => $this->user('CASHIER')->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_director_sees_receipts_today_and_this_month_by_method(): void
    {
        $this->payment(10000, 'CASH', now());
        $this->payment(5000, 'WAVE', now());
        $this->payment(7000, 'CASH', Carbon::parse('2026-11-03 09:00'));
        $this->payment(3000, 'CASH', Carbon::parse('2026-10-20 09:00'));

        $data = $this->actingAs($this->user('ADMIN_CLINIC'), 'sanctum')
            ->getJson('/api/v1/reports/payments-by-method')->assertOk()->json('data');

        $this->assertEquals(15000, $data['today']['total']);
        $this->assertEquals(2, $data['today']['count']);
        $this->assertEquals(22000, $data['month']['total'], 'Le mois exclut le paiement d’octobre.');
        $cash = collect($data['month']['methods'])->firstWhere('method', 'CASH');
        $this->assertEquals(17000, $cash['total']);
        $this->assertSame('Espèces', $cash['label']);
    }

    public function test_cashiers_do_not_see_the_clinic_finance_dashboard(): void
    {
        $this->actingAs($this->user('CASHIER'), 'sanctum')->getJson('/api/v1/reports/payments-by-method')->assertForbidden();
    }

    private function payment(float $amount, string $method, Carbon $at): void
    {
        Payment::create([
            'clinic_id' => $this->clinic->id, 'invoice_id' => $this->invoice->id, 'amount' => $amount,
            'payment_method' => $method, 'payment_datetime' => $at, 'received_by' => $this->user('CASHIER')->id,
            'status' => 'CONFIRMED', 'receipt_number' => 'FIN-'.uniqid(),
        ]);
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
