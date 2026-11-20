<?php

namespace Tests\Feature;

use App\Models\Clinic;
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
use Tests\TestCase;
use ZipArchive;

/** A clinic administrator takes the administrative records of his own clinic out in one ZIP. */
class ClinicDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_clinic_administrator_downloads_his_patients_only_as_csv_inside_a_zip(): void
    {
        [$admin] = $this->clinicWithAdministrator('Clinique Export', 'EXP');
        $other = Clinic::create(['name' => 'Autre clinique', 'city' => 'Moundou', 'country' => 'Tchad', 'code' => 'AUT', 'subscription_plan' => 'BASIC', 'admin_email' => 'aut@example.test', 'status' => 'ACTIVE']);
        Patient::create(['clinic_id' => $admin->clinic_id, 'patient_unique_id' => 'EXP-2026-000001', 'first_name' => 'Amina', 'last_name' => 'Export', 'gender' => 'F', 'date_of_birth' => '1980-05-05']);
        Patient::create(['clinic_id' => $other->id, 'patient_unique_id' => 'AUT-2026-000001', 'first_name' => 'Stranger', 'last_name' => 'Autre', 'gender' => 'M', 'date_of_birth' => '1970-01-01']);

        $response = $this->actingAs($admin, 'sanctum')->get('/api/v1/clinic-data/export')->assertOk();
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('Content-Type'));

        $archive = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($archive, $response->streamedContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive) === true);

        $this->assertNotFalse($zip->locateName('patients.csv'));
        $patients = $zip->getFromName('patients.csv');
        $this->assertStringContainsString('Amina', $patients);
        $this->assertStringNotContainsString('Stranger', $patients, 'another clinic\'s patient must never be exported');

        // Clinical tables are reserved to doctors and nurses by medical secrecy: they are not in the archive.
        $this->assertFalse($zip->locateName('consultations.csv'));
        $this->assertFalse($zip->locateName('vital_signs.csv'));
        $this->assertFalse($zip->locateName('prescriptions.csv'));

        $zip->close();
        @unlink($archive);
    }

    public function test_only_the_clinic_administrator_can_export(): void
    {
        [, $clinic] = $this->clinicWithAdministrator('Clinique Export 2', 'EX2');
        $doctor = User::factory()->create(['clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);

        $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/clinic-data/export')->assertForbidden();
    }

    /** @return array{0: User, 1: Clinic} */
    private function clinicWithAdministrator(string $name, string $code): array
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $clinic = Clinic::create([
            'name' => $name, 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $admin = User::factory()->create([
            'clinic_id' => $clinic->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE',
        ]);

        return [$admin, $clinic];
    }
}
