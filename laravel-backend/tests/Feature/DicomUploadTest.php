<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ImagingExam;
use App\Models\ImagingRequest;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** DICOM series are accepted only when the file really is DICOM (signature after the preamble). */
class DicomUploadTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $radiologist;
    private ImagingRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique DICOM', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'DCM',
            'subscription_plan' => 'PRO', 'admin_email' => 'dcm@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);
        $this->radiologist = User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'RADIOLOGIST')->value('id'), 'status' => 'ACTIVE']);
        $doctor = User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);
        $patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'DCM-2026-000001', 'first_name' => 'Ibrahim', 'last_name' => 'Test']);
        $exam = ImagingExam::create(['clinic_id' => $this->clinic->id, 'exam_type' => 'SCANNER', 'name' => 'Scanner thoracique', 'price' => 50000]);
        $this->request = ImagingRequest::create([
            'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'exam_id' => $exam->id,
            'requested_at' => now(), 'status' => 'IN_PROGRESS', 'priority' => 'ROUTINE',
        ]);
    }

    public function test_a_real_dicom_file_is_stored_as_a_dicom_file(): void
    {
        $dicom = str_repeat("\0", 128).'DICM'.str_repeat("\0", 200);
        $file = UploadedFile::fake()->createWithContent('serie.dcm', $dicom);

        $this->actingAs($this->radiologist, 'sanctum')
            ->post("/api/v1/imaging-requests/{$this->request->id}/files", ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('imaging_files', ['request_id' => $this->request->id, 'file_type' => 'DICOM']);
    }

    public function test_a_renamed_file_is_refused_as_dicom(): void
    {
        $fake = UploadedFile::fake()->createWithContent('image.dcm', str_repeat('x', 400));

        $this->actingAs($this->radiologist, 'sanctum')
            ->post("/api/v1/imaging-requests/{$this->request->id}/files", ['file' => $fake], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('imaging_files', ['request_id' => $this->request->id]);
    }
}
