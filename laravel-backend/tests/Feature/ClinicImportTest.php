<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicImport;
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
use Tests\TestCase;
use ZipArchive;

/** Taking over a clinic's data: check first (nothing written), then import, with duplicates and errors reported. */
class ClinicImportTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        $this->clinic = Clinic::create([
            'name' => 'Clinique Reprise', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'REP',
            'subscription_plan' => 'ENTERPRISE', 'admin_email' => 'rep@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($this->clinic, SubscriptionPlan::where('code', 'ENTERPRISE')->firstOrFail(), 'MONTHLY', false);
        $this->admin = User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'ADMIN_CLINIC')->value('id'), 'status' => 'ACTIVE']);
        Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'IMP-EXIST', 'first_name' => 'Déjà', 'last_name' => 'Présent', 'gender' => 'M', 'date_of_birth' => '1990-01-01']);
    }

    /** @param array<string, list<string>> $tables  table => CSV lines (first line = header), ';' separated */
    private function archive(array $tables): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reprise-'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($tables as $name => $lines) {
            $zip->addFromString($name.'.csv', "\xEF\xBB\xBF".implode("\n", $lines)."\n");
        }
        $zip->close();

        return $path;
    }

    private function sourceArchive(): string
    {
        return $this->archive([
            'patients' => [
                'id;clinic_id;patient_unique_id;first_name;last_name;phone;date_of_birth;gender',
                '1;9;IMP-001;Amina;Source;+235 66 00 00 01;1980-01-01;F',
                '2;9;IMP-EXIST;Doublon;Existant;;1990-01-01;M',
                '3;9;IMP-003;;Sans prénom;;;F',
            ],
            'appointments' => [
                'id;clinic_id;patient_id;doctor_id;appointment_datetime;status',
                '10;9;1;55;2026-11-20 09:00:00;SCHEDULED',
                '11;9;99;55;2026-11-20 10:00:00;SCHEDULED',
            ],
            'invoices' => [
                'id;clinic_id;patient_id;invoice_number;subtotal;tax_amount;tax_rate;total_amount;status;due_date',
                '20;9;1;IMP-FAC-1;5000;0;0;5000;ISSUED;2026-12-01',
            ],
            'invoice_items' => [
                'id;invoice_id;description;amount',
                '30;20;Consultation;5000',
            ],
            'payments' => [
                'id;clinic_id;invoice_id;amount;payment_method;receipt_number;status',
                '40;9;20;2000;CASH;REC-IMP-1;CONFIRMED',
            ],
        ]);
    }

    private function upload(string $zipPath)
    {
        return $this->actingAs($this->admin, 'sanctum')->post('/api/v1/clinic-imports', [
            'file' => new UploadedFile($zipPath, 'export-source.zip', 'application/zip', null, true),
        ]);
    }

    public function test_the_check_lists_duplicates_and_errors_without_writing_anything(): void
    {
        $response = $this->upload($this->sourceArchive())->assertStatus(202);
        $import = ClinicImport::findOrFail($response->json('data.id'));

        $this->assertSame(ClinicImport::CHECKED, $import->status);
        $this->assertSame(3, $import->report['tables']['patients']['rows']);
        $this->assertSame(1, $import->report['tables']['patients']['duplicates']);
        $this->assertSame(1, $import->report['tables']['patients']['errors']);
        $this->assertSame(0, Patient::where('patient_unique_id', 'IMP-001')->count(), 'La vérification n’écrit rien');
    }

    public function test_the_confirmed_import_writes_the_rows_and_links_them_to_their_new_ids(): void
    {
        $import = ClinicImport::findOrFail($this->upload($this->sourceArchive())->json('data.id'));

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/clinic-imports/{$import->id}/confirm")->assertStatus(202);
        $import->refresh();

        $this->assertSame(ClinicImport::DONE, $import->status);
        $this->assertSame(1, $import->report['tables']['patients']['imported']);
        $this->assertSame(1, $import->report['tables']['patients']['duplicates']);
        $this->assertSame(1, $import->report['tables']['appointments']['imported']);
        $this->assertSame(1, $import->report['tables']['appointments']['errors'], 'Le rendez-vous du patient 99 n’a pas de patient importé');

        $patient = Patient::where('patient_unique_id', 'IMP-001')->firstOrFail();
        $this->assertSame($this->clinic->id, $patient->clinic_id);
        $this->assertDatabaseHas('appointments', ['patient_id' => $patient->id, 'clinic_id' => $this->clinic->id]);
        $invoice = \DB::table('invoices')->where('invoice_number', 'IMP-FAC-1')->first();
        $this->assertSame($patient->id, $invoice->patient_id);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id, 'description' => 'Consultation']);
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id, 'receipt_number' => 'REC-IMP-1', 'clinic_id' => $this->clinic->id]);
    }

    public function test_an_archive_cannot_be_confirmed_twice_and_only_the_administrator_imports(): void
    {
        $import = ClinicImport::findOrFail($this->upload($this->sourceArchive())->json('data.id'));
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/clinic-imports/{$import->id}/confirm")->assertStatus(202);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/clinic-imports/{$import->id}/confirm")->assertStatus(422);

        $doctor = User::factory()->create(['clinic_id' => $this->clinic->id, 'role_id' => Role::where('name', 'DOCTOR')->value('id'), 'status' => 'ACTIVE']);
        $this->actingAs($doctor, 'sanctum')->postJson('/api/v1/clinic-imports', [])->assertForbidden();
    }
}
