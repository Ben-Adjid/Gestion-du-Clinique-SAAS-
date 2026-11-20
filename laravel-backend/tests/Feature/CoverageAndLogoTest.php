<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\CoverageSplit;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoverageAndLogoTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $cashier;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        Storage::fake('public');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Ticket', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'TIK',
            'subscription_plan' => 'PRO', 'admin_email' => 'tik@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->cashier = $this->user('CASHIER');
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'TIK-2026-000001', 'first_name' => 'Ibrahim', 'last_name' => 'Test', 'email' => 'ibrahim@example.test']);
        $this->invoice = Invoice::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'invoice_number' => 'TIK-FAC-000001',
            'subtotal' => 17000, 'tax_amount' => 0, 'tax_rate' => 0, 'total_amount' => 17000, 'status' => 'ISSUED',
            'issued_at' => now(), 'due_date' => now()->addDays(7)->toDateString(), 'created_by' => $this->cashier->id,
        ]);
        InvoiceItem::create(['invoice_id' => $this->invoice->id, 'description' => 'Consultation générale', 'amount' => 17000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_insurance_payment_defaults_to_80_percent_and_splits_the_invoice(): void
    {
        Queue::fake();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 13600, 'payment_method' => 'INSURANCE', 'payment_reference' => 'AS-2026-88', 'insurer_name' => 'Assurance Tchad Vie',
        ])->assertCreated();

        $split = CoverageSplit::for($this->invoice->fresh());
        $this->assertSame(80.0, $split['rate']);
        $this->assertSame(13600.0, $split['insurer_share']);
        $this->assertSame(3400.0, $split['patient_share'], 'Le ticket modérateur du patient : 20 %.');
        $this->assertSame(13600.0, $split['paid']);
        $this->assertSame(3400.0, $split['remaining']);
    }

    public function test_the_cashier_can_change_the_rate_and_the_split_follows(): void
    {
        Queue::fake();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 10200, 'payment_method' => 'INSURANCE', 'payment_reference' => 'AS-2026-89', 'insurer_name' => 'IPM', 'coverage_rate' => 60,
        ])->assertCreated();

        $split = CoverageSplit::for($this->invoice->fresh());
        $this->assertSame(10200.0, $split['insurer_share']);
        $this->assertSame(6800.0, $split['patient_share']);
        $this->actingAs($this->cashier, 'sanctum')->getJson("/api/v1/invoices/{$this->invoice->id}")
            ->assertOk()->assertJsonPath('data.coverage.insurer_share', 10200);
    }

    public function test_the_rate_is_refused_outside_insurance_or_above_100(): void
    {
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 1000, 'payment_method' => 'CASH', 'coverage_rate' => 50,
        ])->assertUnprocessable()->assertJsonValidationErrors('coverage_rate');

        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 1000, 'payment_method' => 'INSURANCE', 'payment_reference' => 'X', 'insurer_name' => 'IPM', 'coverage_rate' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors('coverage_rate');
    }

    public function test_the_director_uploads_a_logo_for_his_clinic_only(): void
    {
        $director = $this->user('ADMIN_CLINIC');
        $png = UploadedFile::fake()->createWithContent('logo.png', $this->pngBytes(120, 120));

        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/logo', ['logo' => $png])->assertOk();

        Storage::disk('public')->assertExists("clinics/{$this->clinic->id}/logo.png");
        $this->assertSame("clinics/{$this->clinic->id}/logo.png", $this->clinic->fresh()->settings['logo_path']);
        $this->actingAs($this->cashier, 'sanctum')->postJson('/api/v1/clinic/logo', ['logo' => $png])->assertForbidden();
    }

    public function test_a_logo_above_1_mb_or_of_another_format_is_refused(): void
    {
        $director = $this->user('ADMIN_CLINIC');

        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->create('grand.png', 1500, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');

        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');
    }

    public function test_the_receipt_embeds_the_logo_and_stays_light(): void
    {
        Queue::fake();
        $director = $this->user('ADMIN_CLINIC');
        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $this->pngBytes(120, 120)),
        ])->assertOk();

        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 17000, 'payment_method' => 'CASH',
        ])->assertCreated();
        $payment = \App\Models\Payment::query()->where('invoice_id', $this->invoice->id)->latest('id')->firstOrFail();

        $pdf = $this->actingAs($this->cashier, 'sanctum')->get("/api/v1/payments/{$payment->id}/receipt")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertLessThan(100 * 1024, strlen($pdf->getContent()));
    }

    public function test_the_patient_remainder_counts_only_the_patient_side_payments(): void
    {
        Queue::fake();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 13600, 'payment_method' => 'INSURANCE', 'payment_reference' => 'AS-2026-90', 'insurer_name' => 'IPM',
        ])->assertCreated();
        $this->actingAs($this->cashier, 'sanctum')->postJson("/api/v1/invoices/{$this->invoice->id}/payments", [
            'amount' => 1000, 'payment_method' => 'CASH',
        ])->assertCreated();

        $split = CoverageSplit::for($this->invoice->fresh());
        $this->assertSame(13600.0, $split['paid_insurer']);
        $this->assertSame(1000.0, $split['paid_patient']);
        $this->assertSame(2400.0, $split['patient_remaining'], 'Ticket patient 3 400 − acompte 1 000.');
        $this->assertSame(2400.0, $split['remaining'], 'Solde global : 17 000 − 14 600.');
    }

    public function test_an_svg_disguised_as_a_png_is_refused(): void
    {
        $director = $this->user('ADMIN_CLINIC');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $svg),
        ])->assertUnprocessable()->assertJsonValidationErrors('logo');

        Storage::disk('public')->assertMissing("clinics/{$this->clinic->id}/logo.png");
    }

    public function test_a_large_logo_is_scaled_to_300_pixels_and_re_encoded(): void
    {
        $director = $this->user('ADMIN_CLINIC');

        $this->actingAs($director, 'sanctum')->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->createWithContent('large.png', $this->pngBytes(900, 300)),
        ])->assertOk();

        $stored = Storage::disk('public')->get("clinics/{$this->clinic->id}/logo.png");
        [$width, $height] = getimagesizefromstring($stored);
        $this->assertSame(300, $width);
        $this->assertSame(100, $height);
    }

    public function test_a_clinic_cannot_replace_or_delete_another_clinics_logo(): void
    {
        $directorA = $this->user('ADMIN_CLINIC');
        $this->actingAs($directorA, 'sanctum')->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->createWithContent('a.png', $this->pngBytes(120, 120)),
        ])->assertOk();

        $other = Clinic::create([
            'name' => 'Clinique Voisine', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'VOI',
            'subscription_plan' => 'PRO', 'admin_email' => 'voi@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($other, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $directorB = User::factory()->create([
            'clinic_id' => $other->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE',
        ]);

        $this->actingAs($directorB, 'sanctum')->postJson('/api/v1/clinic/logo', [
            'logo' => UploadedFile::fake()->createWithContent('b.png', $this->pngBytes(120, 120)),
        ])->assertOk();
        $this->actingAs($directorB, 'sanctum')->deleteJson('/api/v1/clinic/logo')->assertOk();

        Storage::disk('public')->assertExists("clinics/{$this->clinic->id}/logo.png");
        $this->assertSame("clinics/{$this->clinic->id}/logo.png", $this->clinic->fresh()->settings['logo_path']);
        Storage::disk('public')->assertMissing("clinics/{$other->id}/logo.png");
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 4, 120, 87));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
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
