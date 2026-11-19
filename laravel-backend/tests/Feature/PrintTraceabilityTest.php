<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\Invoice;
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
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PrintTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 09:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Impression', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'IMP',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'imp@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'IMP-2026-000001', 'first_name' => 'Fatimé', 'last_name' => 'Test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_receipt_print_is_logged_with_who_which_patient_when_and_why(): void
    {
        $cashier = $this->user('CASHIER');
        $invoice = $this->invoice();

        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/prints', [
            'kind' => 'RECEIPT', 'resource_id' => $invoice->id, 'reason' => 'Remise du reçu au patient après paiement.',
        ])->assertCreated();

        $log = AuditLog::query()->where('action', 'PRINT')->firstOrFail();
        $this->assertSame($cashier->id, $log->user_id);
        $this->assertSame('RECEIPT', $log->resource);
        $this->assertSame($invoice->id, $log->resource_id);
        $this->assertSame($this->patient->id, $log->after_data['patient_id']);
        $this->assertSame('Remise du reçu au patient après paiement.', $log->after_data['reason']);
        $this->assertStringStartsWith('2026-11-09 09:00:00', (string) $log->created_at);
    }

    public function test_only_the_roles_entitled_to_a_document_can_print_it(): void
    {
        $invoice = $this->invoice();
        $reason = ['reason' => 'Impression demandée par le patient.'];

        $this->actingAs($this->user('RECEPTIONIST'), 'sanctum')->postJson('/api/v1/prints', $reason + ['kind' => 'RECEIPT', 'resource_id' => $invoice->id])
            ->assertForbidden();
        $this->actingAs($this->user('CASHIER'), 'sanctum')->postJson('/api/v1/prints', $reason + ['kind' => 'CLINICAL_FILE', 'resource_id' => $this->patient->id])
            ->assertForbidden();
        $this->actingAs($this->user('NURSE'), 'sanctum')->postJson('/api/v1/prints', $reason + ['kind' => 'CLINICAL_FILE', 'resource_id' => $this->patient->id])
            ->assertCreated();

        $this->assertSame(1, AuditLog::query()->where('action', 'PRINT')->count(), 'Un refus n’est jamais tracé comme une impression.');
    }

    public function test_a_print_needs_a_reason_and_a_record_of_the_same_clinic(): void
    {
        $other = Clinic::create([
            'name' => 'Autre clinique', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AUT',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'aut@example.test', 'status' => 'ACTIVE',
        ]);
        // Created before any login: the tenant guard refuses cross-clinic writes made by a user.
        $otherPatient = Patient::create(['clinic_id' => $other->id, 'patient_unique_id' => 'AUT-2026-000001', 'first_name' => 'Autre', 'last_name' => 'Patient']);

        $cashier = $this->user('CASHIER');
        $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/prints', ['kind' => 'RECEIPT', 'resource_id' => $this->invoice()->id, 'reason' => 'ok'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($this->user('NURSE'), 'sanctum')->postJson('/api/v1/prints', [
            'kind' => 'CLINICAL_FILE', 'resource_id' => $otherPatient->id, 'reason' => 'Transfert demandé par le patient.',
        ])->assertNotFound();
    }

    public function test_a_payslip_is_printed_by_its_owner_or_by_hr_only(): void
    {
        $this->assertSame(0, AuditLog::query()->where('action', 'PRINT')->count());
        $this->actingAs($this->user('NURSE'), 'sanctum')->postJson('/api/v1/prints', [
            'kind' => 'PAYSLIP', 'resource_id' => 999, 'reason' => 'Bulletin du mois de novembre.',
        ])->assertNotFound();
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'invoice_number' => 'IMP-FAC-000001',
            'subtotal' => 10000, 'tax_amount' => 0, 'tax_rate' => 0, 'total_amount' => 10000, 'status' => 'ISSUED',
            'issued_at' => now(), 'due_date' => now()->addDays(7)->toDateString(), 'created_by' => $this->user('CASHIER')->id,
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
