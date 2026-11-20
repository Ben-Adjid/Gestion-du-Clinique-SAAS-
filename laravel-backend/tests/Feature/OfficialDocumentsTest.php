<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Role;
use App\Models\Surgery;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ClinicLogo;
use App\Services\Prescriptions\PrescriptionPdf;
use App\Services\SubscriptionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Official documents: weight with the clinic logo, shared frame (pagination, verification, French notices). */
class OfficialDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_BYTES = 102400;

    private Clinic $clinic;
    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        Storage::fake('public');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Documents', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'DOC',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'doc@example.test', 'status' => 'ACTIVE',
        ]);
        // The surgery module (operative report, consent) is part of the ENTERPRISE plan only.
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->doctor = User::factory()->create([
            'clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE',
            'name' => 'Dr Fatimé Test',
        ]);
        $this->doctor->doctorProfile()->create(['clinic_id' => $this->clinic->id, 'specialty' => 'Médecine générale', 'license_number' => 'ONM-TEST-0001']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Text of every page, read from the (possibly compressed) content streams. */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        $text = '';
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $text .= $inflated !== false ? $inflated : $stream;
        }

        return $text;
    }

    private function withClinicLogo(): void
    {
        ClinicLogo::store($this->clinic, UploadedFile::fake()->image('logo.png', 600, 600));
    }

    private function signedPrescription(): Prescription
    {
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'DOC-2026-000001', 'first_name' => 'Aïcha', 'last_name' => 'Test', 'date_of_birth' => '1990-03-03']);
        $consultation = Consultation::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id,
            'consultation_datetime' => now(), 'status' => 'SIGNED',
        ]);
        $prescription = Prescription::create([
            'clinic_id' => $this->clinic->id, 'consultation_id' => $consultation->id, 'doctor_id' => $this->doctor->id,
            'prescribed_at' => now(), 'status' => 'SIGNED',
        ]);
        $medication = Medication::create(['clinic_id' => $this->clinic->id, 'name' => 'Amlodipine', 'dosage' => '5 mg', 'form' => 'Comprimé', 'price' => 1500]);
        PrescriptionItem::create([
            'clinic_id' => $this->clinic->id, 'prescription_id' => $prescription->id, 'medication_id' => $medication->id,
            'dosage_prescribed' => '5 mg', 'frequency' => '1 fois par jour', 'duration_days' => 5, 'quantity' => 5,
        ]);

        return $prescription->fresh(['items', 'consultation.patient', 'doctor.doctorProfile', 'clinic']);
    }

    private function surgery(): Surgery
    {
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'DOC-2026-000002', 'first_name' => 'Moussa', 'last_name' => 'Test', 'date_of_birth' => '1975-05-05']);

        return Surgery::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'operation_type' => 'Appendicectomie',
            'scheduled_at' => '2026-11-25 08:00:00', 'status' => 'PLANNED', 'surgeon_id' => $this->doctor->id,
            'created_by' => $this->doctor->id,
        ]);
    }

    public function test_a_signed_prescription_with_the_clinic_logo_weighs_under_100_kilobytes(): void
    {
        $this->withClinicLogo();

        $pdf = PrescriptionPdf::render($this->signedPrescription());

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertLessThan(self::MAX_BYTES, strlen($pdf), 'Le PDF avec logo dépasse 100 Ko.');
    }

    public function test_the_frame_prints_pagination_the_verification_code_and_the_cgu_version(): void
    {
        $prescription = $this->signedPrescription();

        $text = $this->pdfText(PrescriptionPdf::render($prescription));

        $this->assertStringContainsString('Page 1 / 1', $text);
        $this->assertStringContainsString('version 2026-11', $text);
        $this->assertStringContainsString(PrescriptionPdf::verificationCode($prescription), $text);
        $this->assertStringNotContainsString('Dr Dr', $text);
    }

    public function test_a_missing_licence_is_explained_in_french_instead_of_a_placeholder(): void
    {
        $text = $this->pdfText(PrescriptionPdf::render($this->signedPrescription()));

        $this->assertStringNotContainsString('Non renseigné', $text);
        $this->assertStringContainsString('le directeur doit saisir l', $text);
    }

    public function test_the_operative_report_cannot_be_printed_before_it_is_signed(): void
    {
        $surgery = $this->surgery();

        $this->actingAs($this->doctor, 'sanctum')
            ->get("/api/v1/surgeries/{$surgery->id}/documents/operative-report")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le compte rendu doit être signé avant d’être imprimé.');
    }

    public function test_a_signed_operative_report_is_a_pdf_under_100_kilobytes_with_the_logo(): void
    {
        $this->withClinicLogo();
        $surgery = $this->surgery();
        $surgery->update(['report' => 'Incision de Mac Burney, appendice réséquée, suites simples.', 'report_signed_at' => now(), 'status' => 'COMPLETED']);

        $pdf = $this->actingAs($this->doctor, 'sanctum')
            ->get("/api/v1/surgeries/{$surgery->id}/documents/operative-report")
            ->assertOk()
            ->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertLessThan(self::MAX_BYTES, strlen($pdf));
        $this->assertStringContainsString('ONM-TEST-0001', $this->pdfText($pdf));
    }

    public function test_a_hospital_file_of_an_unknown_stay_is_not_found(): void
    {
        $this->actingAs($this->doctor, 'sanctum')
            ->get('/api/v1/admissions/999999/documents/hospital-file')
            ->assertNotFound();
    }
}
