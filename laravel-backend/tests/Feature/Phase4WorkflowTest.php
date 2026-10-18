<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\ImagingExam;
use App\Models\ImagingRequest;
use App\Models\Invoice;
use App\Models\LabExam;
use App\Models\LabRequest;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PharmacyInventory;
use App\Models\PharmacyLot;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceReminders;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase4WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $doctor;
    private User $pharmacist;
    private User $cashier;
    private Patient $patient;
    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->clinic = $this->makeClinic('PHASE4');
        $this->doctor = $this->user('DOCTOR', $this->clinic);
        $this->pharmacist = $this->user('PHARMACIST', $this->clinic);
        $this->cashier = $this->user('CASHIER', $this->clinic);
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'PHASE4-2026-000001', 'first_name' => 'Achta', 'last_name' => 'Mahamat']);
        $this->consultation = Consultation::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'consultation_datetime' => now(), 'status' => 'SIGNED',
        ]);
    }

    public function test_dispensing_follows_fefo_never_uses_expired_lots_and_can_be_partial(): void
    {
        [$medication, $inventory] = $this->medication('Amoxicilline', 1500);
        $expired = $this->lot($inventory, 'OLD', now()->subDay(), 50);
        $late = $this->lot($inventory, 'LATE', now()->addYear(), 10);
        $soon = $this->lot($inventory, 'SOON', now()->addMonth(), 4);
        $prescription = $this->prescription($medication, 10);

        $this->actingAs($this->pharmacist, 'sanctum');
        $this->postJson("/api/v1/prescriptions/{$prescription->id}/dispense", ['items' => [['prescription_item_id' => $prescription->items[0]->id, 'quantity' => 11]]])
            ->assertUnprocessable();

        $this->postJson("/api/v1/prescriptions/{$prescription->id}/dispense", ['items' => [['prescription_item_id' => $prescription->items[0]->id, 'quantity' => 6]]])
            ->assertOk()
            ->assertJsonPath('data.status', 'SIGNED')
            ->assertJsonPath('sale.complete', false)
            ->assertJsonPath('sale.total_amount', 9000);
        $this->assertSame(0, $soon->fresh()->quantity, 'the lot expiring first is used first');
        $this->assertSame(8, $late->fresh()->quantity);
        $this->assertSame(50, $expired->fresh()->quantity, 'expired stock is never dispensed');
        $this->assertSame(4, $prescription->items[0]->fresh()->remaining_quantity);

        // Only 8 dispensable units left (expired lot excluded): asking 4 is fine and closes the prescription.
        $this->postJson("/api/v1/prescriptions/{$prescription->id}/dispense")
            ->assertOk()
            ->assertJsonPath('data.status', 'DISPENSED');
        $this->assertSame(4, $late->fresh()->quantity);
        $this->assertSame(54, $inventory->fresh()->quantity);
        $this->getJson('/api/v1/pharmacy/stock')->assertOk()
            ->assertJsonPath('data.0.available_quantity', 4)
            ->assertJsonPath('data.0.expired_quantity', 50)
            ->assertJsonPath('summary.expired', 1);
    }

    public function test_reception_and_adjustment_rules(): void
    {
        [$medication, $inventory] = $this->medication('Paracétamol', 500);
        $this->actingAs($this->pharmacist, 'sanctum');
        $payload = ['medication_id' => $medication->id, 'lot_number' => 'cip-001', 'expiration_date' => now()->addYear()->toDateString(), 'quantity' => 100, 'unit_cost' => 250];

        $this->postJson('/api/v1/pharmacy/receptions', [...$payload, 'expiration_date' => now()->subDay()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/v1/pharmacy/receptions', $payload)->assertCreated()->assertJsonPath('data.lot_number', 'CIP-001');
        $this->postJson('/api/v1/pharmacy/receptions', [...$payload, 'quantity' => 20])->assertCreated();
        $this->postJson('/api/v1/pharmacy/receptions', [...$payload, 'expiration_date' => now()->addYears(2)->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('lot_number');
        $this->assertSame(120, $inventory->fresh()->quantity);

        $lot = PharmacyLot::firstOrFail();
        $this->postJson('/api/v1/pharmacy/adjustments', ['lot_id' => $lot->id, 'quantity_change' => -121, 'reason' => 'DAMAGED'])->assertUnprocessable();
        $this->postJson('/api/v1/pharmacy/adjustments', ['lot_id' => $lot->id, 'quantity_change' => -5, 'reason' => 'DAMAGED', 'notes' => 'Boîte écrasée'])->assertOk();
        $this->assertSame(115, $inventory->fresh()->quantity);
        $this->getJson('/api/v1/pharmacy/movements')->assertOk()->assertJsonPath('total', 3);

        // A cashier cannot touch the stock.
        $this->actingAs($this->cashier, 'sanctum')->postJson('/api/v1/pharmacy/receptions', $payload)->assertForbidden();
    }

    public function test_services_are_billed_once_and_become_billable_again_when_the_invoice_is_cancelled(): void
    {
        $lab = LabExam::create(['clinic_id' => $this->clinic->id, 'exam_type' => 'HEMATOLOGY', 'name' => 'NFS', 'price' => 5000]);
        $labRequest = LabRequest::create(['clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'consultation_id' => $this->consultation->id, 'requested_at' => now(), 'status' => 'PENDING']);
        $labRequest->exams()->sync([$lab->id]);
        $imagingExam = ImagingExam::create(['clinic_id' => $this->clinic->id, 'exam_type' => 'XRAY', 'name' => 'Radio thorax', 'price' => 15000]);
        ImagingRequest::create(['clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'exam_id' => $imagingExam->id, 'consultation_id' => $this->consultation->id, 'requested_at' => now(), 'status' => 'PENDING']);
        [$medication, $inventory] = $this->medication('SRO', 200);
        $this->lot($inventory, 'SRO-1', now()->addYear(), 50);
        $this->actingAs($this->pharmacist, 'sanctum')
            ->postJson('/api/v1/pharmacy/sales', ['patient_id' => $this->patient->id, 'items' => [['medication_id' => $medication->id, 'quantity' => 5]]])
            ->assertCreated();

        $this->actingAs($this->cashier, 'sanctum');
        $this->getJson('/api/v1/billing/pending')->assertOk()->assertJsonPath('data.0.acts_count', 4)->assertJsonPath('data.0.total', 31000);
        $acts = $this->getJson("/api/v1/billing/pending?patient_id={$this->patient->id}")->json('data');
        $items = array_map(fn (array $act) => ['description' => $act['description'], 'amount' => $act['amount'], 'reference_id' => $act['reference']], $acts);

        $invoiceId = $this->postJson('/api/v1/invoices', ['patient_id' => $this->patient->id, 'items' => $items, 'discount' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '30000.00')
            ->assertJsonPath('data.invoice_number', 'PHASE4-'.now()->year.'-000001')
            ->json('data.id');
        $this->getJson('/api/v1/billing/pending')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/invoices', ['patient_id' => $this->patient->id, 'items' => [$items[0]]])
            ->assertUnprocessable();

        $this->postJson("/api/v1/invoices/{$invoiceId}/cancel", ['reason' => 'Erreur de tarif'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->getJson('/api/v1/billing/pending')->assertJsonPath('data.0.acts_count', 4);

        $paidId = $this->postJson('/api/v1/invoices', ['patient_id' => $this->patient->id, 'items' => $items])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$paidId}/payments", ['amount' => 1000, 'payment_method' => 'CASH'])->assertCreated();
        $this->postJson("/api/v1/invoices/{$paidId}/cancel", ['reason' => 'Tentative après paiement'])->assertUnprocessable();
    }

    public function test_daily_cash_closing(): void
    {
        $this->actingAs($this->cashier, 'sanctum');
        $invoiceId = $this->postJson('/api/v1/invoices', ['patient_id' => $this->patient->id, 'items' => [['description' => 'Consultation', 'amount' => 10000]]])->json('data.id');
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 6000, 'payment_method' => 'CASH'])->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 3000, 'payment_method' => 'MOBILE_MONEY', 'payment_reference' => 'OM-1'])->assertCreated();

        $this->getJson('/api/v1/cash/summary')->assertOk()
            ->assertJsonPath('data.totals.CASH', 6000)
            ->assertJsonPath('data.totals.MOBILE_MONEY', 3000)
            ->assertJsonPath('data.can_close', true);
        $this->postJson('/api/v1/cash/closings', ['counted_cash' => 5500])->assertStatus(422);
        $this->postJson('/api/v1/cash/closings', ['counted_cash' => 5500, 'notes' => 'Monnaie rendue en trop'])
            ->assertCreated()
            ->assertJsonPath('data.difference', -500)
            ->assertJsonPath('data.payments_count', 2);
        $this->postJson('/api/v1/cash/closings', ['counted_cash' => 0, 'notes' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 1000, 'payment_method' => 'CASH'])->assertStatus(422);

        // The clinic administrator sees every cashier's closing.
        $this->actingAs($this->user('ADMIN_CLINIC', $this->clinic), 'sanctum')
            ->getJson('/api/v1/cash/closings')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_overdue_invoices_get_up_to_three_reminders(): void
    {
        $this->actingAs($this->cashier, 'sanctum');
        $invoiceId = $this->postJson('/api/v1/invoices', ['patient_id' => $this->patient->id, 'items' => [['description' => 'Consultation', 'amount' => 10000]]])->json('data.id');
        $reminders = app(InvoiceReminders::class);

        $this->assertSame(['overdue' => 0, 'reminders' => 0], $reminders->run(now()));
        $this->assertSame(['overdue' => 1, 'reminders' => 1], $reminders->run(now()->addDays(4)));
        $this->assertSame('OVERDUE', Invoice::find($invoiceId)->status);
        $this->assertSame(0, $reminders->run(now()->addDays(5))['reminders'], 'no second reminder within three days');
        $reminders->run(now()->addDays(8));
        $reminders->run(now()->addDays(12));
        $this->assertSame(0, $reminders->run(now()->addDays(20))['reminders'], 'at most three reminders');
        $this->assertDatabaseCount('payment_reminders', 3);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->cashier->id, 'type' => 'INVOICE_REMINDER']);
    }

    public function test_imaging_files_are_uploaded_after_the_exam_and_stay_inside_the_clinic(): void
    {
        Storage::fake('local');
        $exam = ImagingExam::create(['clinic_id' => $this->clinic->id, 'exam_type' => 'XRAY', 'name' => 'Radio thorax', 'price' => 15000]);
        $request = ImagingRequest::create(['clinic_id' => $this->clinic->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'exam_id' => $exam->id, 'consultation_id' => $this->consultation->id, 'requested_at' => now(), 'status' => 'PENDING']);
        $radiologist = $this->user('RADIOLOGIST', $this->clinic);
        $pdf = UploadedFile::fake()->create('cliche.pdf', 120, 'application/pdf');

        $this->actingAs($radiologist, 'sanctum');
        $this->post("/api/v1/imaging-requests/{$request->id}/files", ['file' => $pdf], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->patchJson("/api/v1/imaging-requests/{$request->id}", ['status' => 'IN_PROGRESS'])->assertOk();
        $fileId = $this->post("/api/v1/imaging-requests/{$request->id}/files", ['file' => $pdf], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.file_type', 'PDF')->assertJsonMissingPath('data.file_path')->json('data.id');
        $this->post("/api/v1/imaging-requests/{$request->id}/files", ['file' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->get("/api/v1/imaging-requests/{$request->id}/files/{$fileId}")->assertOk();

        $other = $this->makeClinic('OTHER4');
        $this->actingAs($this->user('RADIOLOGIST', $other, 'foreign'), 'sanctum')
            ->get("/api/v1/imaging-requests/{$request->id}/files/{$fileId}", ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->actingAs($this->user('CASHIER', $other, 'foreign'), 'sanctum')
            ->getJson('/api/v1/billing/pending')->assertOk()->assertJsonCount(0, 'data');
    }

    private function medication(string $name, float $price): array
    {
        $medication = Medication::create(['clinic_id' => $this->clinic->id, 'name' => $name, 'dosage' => '500 mg', 'form' => 'Comprimé', 'price' => $price]);
        $inventory = PharmacyInventory::create(['clinic_id' => $this->clinic->id, 'medication_id' => $medication->id, 'quantity' => 0, 'unit_cost' => $price / 2, 'selling_price' => $price, 'min_threshold' => 5]);

        return [$medication, $inventory];
    }

    private function lot(PharmacyInventory $inventory, string $number, $expiry, int $quantity): PharmacyLot
    {
        $inventory->increment('quantity', $quantity);

        return PharmacyLot::create([
            'inventory_id' => $inventory->id, 'lot_number' => $number, 'manufacturing_date' => now()->subYear()->toDateString(),
            'expiration_date' => $expiry->toDateString(), 'quantity' => $quantity, 'initial_quantity' => $quantity,
        ]);
    }

    private function prescription(Medication $medication, int $quantity): Prescription
    {
        $prescription = Prescription::create(['clinic_id' => $this->clinic->id, 'consultation_id' => $this->consultation->id, 'doctor_id' => $this->doctor->id, 'prescribed_at' => now(), 'status' => 'SIGNED']);
        $prescription->items()->create(['medication_id' => $medication->id, 'dosage_prescribed' => '1 gélule', 'frequency' => '3 fois par jour', 'duration_days' => 5, 'quantity' => $quantity]);

        return $prescription->load('items');
    }

    private function user(string $role, Clinic $clinic, string $prefix = 'user'): User
    {
        return User::factory()->create([
            'email' => $prefix.'-'.strtolower($role).'-'.$clinic->id.'@example.test',
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    private function makeClinic(string $code): Clinic
    {
        return Clinic::create([
            'name' => "Clinique {$code}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => 'PRO', 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
    }
}
