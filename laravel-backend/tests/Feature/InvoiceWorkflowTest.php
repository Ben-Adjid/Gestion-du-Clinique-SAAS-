<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_totals_and_items_are_created_from_the_billing_schema(): void
    {
        [$clinic, $cashier, $patient] = $this->makeCashier();

        $response = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'description' => 'Consultation',
            'subtotal' => 1000,
            'tax_rate' => 10,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.clinic_id', $clinic->id)
            ->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.subtotal', '1000.00')
            ->assertJsonPath('data.tax_amount', '100.00')
            ->assertJsonPath('data.total_amount', '1100.00')
            ->assertJsonPath('data.items.0.description', 'Consultation');

        $this->assertDatabaseHas('invoices', [
            'patient_id' => $patient->id,
            'clinic_id' => $clinic->id,
            'total_amount' => 1100,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'clinic_id' => $clinic->id,
            'user_id' => $cashier->id,
            'resource' => 'INVOICE',
            'action' => 'CREATE',
        ]);
    }

    public function test_partial_payments_cannot_exceed_the_invoice_balance_and_set_paid_when_settled(): void
    {
        [, $cashier, $patient] = $this->makeCashier();
        $invoiceResponse = $this->actingAs($cashier, 'sanctum')->postJson('/api/v1/invoices', [
            'patient_id' => $patient->id,
            'description' => 'Examen',
            'subtotal' => 1000,
            'tax_rate' => 0,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $invoiceResponse->assertCreated();
        $invoiceId = $invoiceResponse->json('data.id');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 400,
            'payment_method' => 'CASH',
        ])->assertCreated()->assertJsonPath('invoice.status', 'ISSUED');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 601,
            'payment_method' => 'CASH',
        ])->assertUnprocessable();

        // Mobile money is not taken for a clinic bill any more: only cash, cheque or the insurer's share.
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 600,
            'payment_method' => 'MOBILE_MONEY',
            'payment_reference' => 'OM2610.1234',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
            'amount' => 600,
            'payment_method' => 'CHEQUE',
            'cheque_number' => '0001234',
            'cheque_bank' => 'Ecobank Tchad',
            'cheque_drawer' => 'Test Chèque',
            'cheque_date' => '2026-11-19',
        ])->assertCreated()->assertJsonPath('invoice.status', 'PAID');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'status' => 'PAID',
        ]);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_report_overview_uses_live_clinic_data(): void
    {
        [$clinic, $cashier, $patient] = $this->makeCashier();

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/reports/overview?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.clinic_count', 1)
            ->assertJsonPath('data.patients.total', 1)
            ->assertJsonPath('data.patients.new', 1)
            ->assertJsonPath('data.appointments.total', 0)
            ->assertJsonPath('data.active_staff', 1);

        $this->assertSame($clinic->id, $patient->clinic_id);
    }

    private function makeCashier(): array
    {
        $clinic = Clinic::create([
            'name' => 'Clinique Facturation',
            'city' => 'Dakar',
            'country' => 'Sénégal',
            'code' => 'FACTURATION',
            'subscription_plan' => 'BASIC',
            'admin_email' => 'billing@example.test',
            'status' => 'ACTIVE',
        ]);
        $role = Role::create(['name' => 'CASHIER', 'display_name' => 'Caissier']);
        $permissionIds = collect(['create:invoice', 'read:invoice', 'update:invoice', 'manage:payment', 'view:reports'])
            ->map(fn (string $name) => Permission::create([
                'name' => $name,
                'resource' => 'invoice',
                'action' => str_contains($name, ':') ? explode(':', $name, 2)[0] : $name,
            ])->id);
        $role->permissions()->attach($permissionIds);
        $cashier = User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => $role->id,
        ]);
        $patient = Patient::create([
            'clinic_id' => $clinic->id,
            'patient_unique_id' => 'FACTURATION-2026-00001',
            'first_name' => 'Aïssatou',
            'last_name' => 'Test',
        ]);

        return [$clinic, $cashier, $patient];
    }
}
