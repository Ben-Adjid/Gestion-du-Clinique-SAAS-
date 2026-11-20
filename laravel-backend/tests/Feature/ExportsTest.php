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

/** OHADA journal and insurance bordereau: Enterprise only, semicolon CSV, French refusal on lower plans. */
class ExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 12:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A clinic on the plan, an administrator, one insured and paid invoice. */
    private function clinicWithInsurancePayment(string $plan, string $code): User
    {
        $clinic = Clinic::create([
            'name' => "Clinique {$code}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => $plan, 'admin_email' => "{$code}@example.test", 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', $plan)->firstOrFail(), 'MONTHLY', false);
        $admin = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE']);

        $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_unique_id' => "{$code}-2026-000001", 'first_name' => 'Amina', 'last_name' => 'Abakar', 'date_of_birth' => '1997-01-01']);
        $invoice = Invoice::create([
            'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'invoice_number' => "{$code}-2026-000154",
            'subtotal' => 150000, 'tax_amount' => 0, 'total_amount' => 150000, 'status' => 'PAID',
            'created_by' => $admin->id, 'insurance_rate' => 80,
        ]);
        Payment::create([
            'clinic_id' => $clinic->id, 'invoice_id' => $invoice->id, 'amount' => 120000, 'payment_method' => 'INSURANCE',
            'payment_reference' => 'AS-TEST-154', 'insurer_name' => 'Mutuelle test', 'receipt_number' => "REC-{$code}-0140",
            'payment_datetime' => now(), 'status' => 'CONFIRMED', 'received_by' => $admin->id,
        ]);

        return $admin;
    }

    public function test_enterprise_exports_the_insurance_bordereau_with_the_insurer_line(): void
    {
        $admin = $this->clinicWithInsurancePayment('ENTERPRISE', 'ENT');

        $csv = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/exports/insurance-bordereau?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('REC-ENT-0140', $csv);
        $this->assertStringContainsString('AS-TEST-154', $csv);
        $this->assertStringContainsString('120000,00', $csv);
        $this->assertStringContainsString('TOTAL', $csv);
    }

    public function test_enterprise_exports_the_ohada_journal_balanced_by_document(): void
    {
        $admin = $this->clinicWithInsurancePayment('ENTERPRISE', 'EOH');

        $csv = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/exports/ohada-journal?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->getContent();

        // Invoice: 411 debit / 706 credit. Insurance payment: 4117 debit / 411 credit.
        $this->assertStringContainsString('411', $csv);
        $this->assertStringContainsString('706', $csv);
        $this->assertStringContainsString('4117', $csv);
    }

    public function test_pro_refuses_the_exports_with_the_french_upsell(): void
    {
        $admin = $this->clinicWithInsurancePayment('PRO', 'PRO');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/exports/insurance-bordereau?from=2026-11-01&to=2026-11-30')
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED')
            ->assertJsonPath('upgrade_to', 'ENTERPRISE');
    }

    public function test_a_period_is_required_in_french(): void
    {
        $admin = $this->clinicWithInsurancePayment('ENTERPRISE', 'ENT2');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/exports/ohada-journal')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from']);
    }
}
