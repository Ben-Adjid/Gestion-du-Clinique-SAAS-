<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Prescriptions\PrescriptionPdf;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrescriptionPdfTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $doctor;
    private Consultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Ordonnance', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'ORD',
            'subscription_plan' => 'PRO', 'admin_email' => 'ord@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->doctor = User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'ORD-2026-000001', 'first_name' => 'Yacine', 'last_name' => 'Test', 'date_of_birth' => '1980-02-02']);
        $this->consultation = Consultation::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id,
            'consultation_datetime' => now(), 'status' => 'SIGNED',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_signed_prescription_is_a_light_pdf_with_its_verification_code(): void
    {
        $prescription = $this->prescription('SIGNED');

        $response = $this->actingAs($this->doctor, 'sanctum')->get("/api/v1/prescriptions/{$prescription->id}/pdf")->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertLessThan(100 * 1024, strlen($response->getContent()));
        $code = $this->actingAs($this->doctor, 'sanctum')->getJson("/api/v1/prescriptions/{$prescription->id}")->json('data.verification_code');
        $this->assertNotNull($code, 'Une ordonnance signée porte un code de vérification.');
    }

    public function test_a_draft_prescription_cannot_be_printed(): void
    {
        $prescription = $this->prescription('DRAFT');

        $this->actingAs($this->doctor, 'sanctum')->get("/api/v1/prescriptions/{$prescription->id}/pdf")->assertUnprocessable();
    }

    public function test_a_prescription_of_another_clinic_is_not_found(): void
    {
        $prescription = $this->prescription('SIGNED');
        $other = Clinic::create([
            'name' => 'Autre ordonnance', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AOR',
            'subscription_plan' => 'PRO', 'admin_email' => 'aor@example.test', 'status' => 'ACTIVE',
        ]);
        $outsider = User::factory()->create(['clinic_id' => $other->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($outsider, 'sanctum')->get("/api/v1/prescriptions/{$prescription->id}/pdf")->assertNotFound();
    }

    public function test_the_printed_code_is_the_one_the_prescription_screen_shows(): void
    {
        $prescription = $this->prescription('SIGNED');
        $code = \App\Services\Prescriptions\PrescriptionPdf::verificationCode($prescription);

        $html = \App\Services\Prescriptions\PrescriptionPdf::html($prescription);
        $this->assertStringContainsString($code, $html);
        $this->assertSame($code, $this->actingAs($this->doctor, 'sanctum')
            ->getJson("/api/v1/prescriptions/{$prescription->id}")->json('data.verification_code'));
    }

    public function test_the_prescription_with_the_clinic_logo_stays_under_100_kilobytes(): void
    {
        Storage::fake('public');
        $logo = imagecreatetruecolor(900, 300);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 4, 120, 87));
        ob_start();
        imagepng($logo);
        Storage::disk('public')->put("clinics/{$this->clinic->id}/logo.png", (string) ob_get_clean());
        $this->clinic->update(['settings' => ['logo_path' => "clinics/{$this->clinic->id}/logo.png", 'license_number' => 'AG-2026-17']]);

        $prescription = $this->prescription('SIGNED');
        $html = \App\Services\Prescriptions\PrescriptionPdf::html($prescription->fresh());
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('AG-2026-17', $html);

        $response = $this->actingAs($this->doctor, 'sanctum')->get("/api/v1/prescriptions/{$prescription->id}/pdf")->assertOk();
        $this->assertLessThan(100 * 1024, strlen($response->getContent()));
    }

    public function test_a_prescription_cannot_be_signed_without_the_doctors_licence_number(): void
    {
        $draft = $this->prescription('DRAFT');

        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$draft->id}/sign")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'LICENCE_REQUIRED')
            ->assertJsonPath('message', 'Renseignez votre numéro d’ordre dans vos paramètres avant de signer une ordonnance.');
        $this->assertSame('DRAFT', $draft->fresh()->status);
    }

    public function test_a_prescription_is_signed_once_the_licence_number_is_set(): void
    {
        DoctorProfile::create(['clinic_id' => $this->clinic->id, 'user_id' => $this->doctor->id, 'license_number' => 'ONM-2026-0417']);
        $draft = $this->prescription('DRAFT');

        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$draft->id}/sign")->assertOk();
        $this->assertSame('SIGNED', $draft->fresh()->status);
        $this->assertStringContainsString('N° d’ordre : ONM-2026-0417', PrescriptionPdf::html($draft->fresh()));
    }

    public function test_the_doctor_records_his_own_licence_and_then_signs(): void
    {
        $draft = $this->prescription('DRAFT');
        $this->actingAs($this->doctor, 'sanctum')->putJson('/api/v1/me/doctor-licence', ['license_number' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('license_number');

        $this->actingAs($this->doctor, 'sanctum')->putJson('/api/v1/me/doctor-licence', ['license_number' => 'ONM-2026-0417'])
            ->assertOk()->assertJsonPath('data.license_number', 'ONM-2026-0417');
        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$draft->id}/sign")->assertOk();
        $this->assertSame('SIGNED', $draft->fresh()->status);
    }

    public function test_a_nurse_cannot_record_a_licence_number(): void
    {
        $nurse = User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'NURSE')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($nurse, 'sanctum')->putJson('/api/v1/me/doctor-licence', ['license_number' => 'ONM-1'])->assertForbidden();
    }

    private function prescription(string $status): Prescription
    {
        return Prescription::create([
            'clinic_id' => $this->clinic->id, 'consultation_id' => $this->consultation->id, 'doctor_id' => $this->doctor->id,
            'prescribed_at' => now(), 'status' => $status, 'notes' => 'Boire beaucoup d’eau.',
        ]);
    }
}
