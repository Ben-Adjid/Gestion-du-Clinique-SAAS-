<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ImagingExam;
use App\Models\ImagingReport;
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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** A signed imaging report is attached to the patient's record: its opening text appears in the timeline. */
class TimelineImagingReportTest extends TestCase
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

    public function test_the_signed_report_text_is_in_the_patient_timeline(): void
    {
        $clinic = Clinic::create([
            'name' => 'Clinique Imagerie', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'IMG',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'img@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $doctor = $this->user($clinic, 'DOCTOR');
        $radiologist = $this->user($clinic, 'RADIOLOGIST');
        $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_unique_id' => 'IMG-2026-000001', 'first_name' => 'Issa', 'last_name' => 'Test', 'date_of_birth' => '1972-06-06']);
        $exam = ImagingExam::create(['clinic_id' => $clinic->id, 'exam_type' => 'XRAY', 'name' => 'Radiographie du thorax (face)']);
        $request = ImagingRequest::create([
            'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'exam_id' => $exam->id, 'requested_at' => now(), 'status' => 'SIGNED', 'priority' => 'ROUTINE',
        ]);
        ImagingReport::create([
            'request_id' => $request->id, 'radiologist_id' => $radiologist->id,
            'report_text' => 'Poumons clairs, pas d’épanchement pleural. Conclusion : examen normal.', 'signed_at' => now(),
        ]);

        $response = $this->actingAs($doctor, 'sanctum')->getJson("/api/v1/patients/{$patient->id}/timeline")->assertOk();

        $event = collect($response->json('data'))->firstWhere('type', 'imaging');
        $this->assertNotNull($event, 'L’imagerie doit figurer dans la chronologie.');
        $this->assertStringContainsString('Radiographie du thorax (face)', $event['detail']);
        $this->assertStringContainsString('Compte rendu : Poumons clairs', $event['detail']);
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
