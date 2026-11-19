<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Import\PatientImporter;
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
use ZipArchive;

class PatientImportTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 09:00:00');
        Storage::fake('local');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, SubscriptionPlanSeeder::class]);
        [$this->clinic, $this->director] = $this->makeClinic('IMPCLI');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_template_is_a_semicolon_csv_with_bom_and_example_rows(): void
    {
        $response = $this->actingAs($this->director, 'sanctum')
            ->get('/api/v1/imports/patients/template')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="modele-import-patients.csv"');

        $content = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF".'nom;prenom;sexe;date_naissance', $content);
        $this->assertSame(4, substr_count(trim($content), "\n") + 1);
    }

    public function test_the_template_itself_imports_without_errors(): void
    {
        $file = UploadedFile::fake()->createWithContent('modele.csv', app(PatientImporter::class)->template());

        $this->actingAs($this->director, 'sanctum')
            ->post('/api/v1/imports/patients/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('valid', 3)
            ->assertJsonPath('rejected', 0);
    }

    public function test_preview_reports_each_invalid_line_and_duplicates(): void
    {
        Patient::create([
            'clinic_id' => $this->clinic->id, 'patient_unique_id' => 'IMPCLI-2026-000001',
            'first_name' => 'Fatimé', 'last_name' => 'ZAKARIA', 'date_of_birth' => '1985-01-10', 'national_id' => 'CNI-EXIST',
        ]);
        $csv = implode("\n", [
            'Nom;Prénom;Sexe;Date de naissance;Téléphone;Email;CNI;Groupe sanguin',
            'Hassan;Ali;M;01/02/1990;+235 66 00 00 01;ali@example.com;CNI-1;O+',
            ';Sans nom;F;;;;;',
            'Idriss;Amina;X;31/02/2001;12;pas-un-mail;;Z+',
            'Hassan;Ali;M;01/02/1990;+235 66 00 00 01;;;',
            'Zakaria;Fatimé;F;10/01/1985;;;;',
            'Nouveau;Patient;F;;;;CNI-EXIST;',
        ]);
        $file = UploadedFile::fake()->createWithContent('patients.csv', $csv);

        $response = $this->actingAs($this->director, 'sanctum')
            ->post('/api/v1/imports/patients/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonPath('valid', 1)
            ->assertJsonPath('rejected', 5)
            ->assertJsonPath('preview.0.last_name', 'HASSAN')
            ->assertJsonPath('preview.0.date_of_birth', '1990-02-01');

        $errors = collect($response->json('errors'));
        $this->assertTrue($errors->contains(fn ($e) => $e['line'] === 3 && $e['field'] === 'last_name'));
        foreach (['gender', 'date_of_birth', 'phone', 'email', 'blood_group'] as $field) {
            $this->assertTrue($errors->contains(fn ($e) => $e['line'] === 4 && $e['field'] === $field), "Erreur {$field} attendue ligne 4");
        }
        $this->assertTrue($errors->contains(fn ($e) => $e['line'] === 5 && str_contains($e['message'], 'Doublon de la ligne 2')));
        $this->assertTrue($errors->contains(fn ($e) => $e['line'] === 6 && str_contains($e['message'], 'même nom et date de naissance')));
        $this->assertTrue($errors->contains(fn ($e) => $e['line'] === 7 && str_contains($e['message'], 'même pièce d’identité')));
        $this->assertSame(1, Patient::count(), 'L’analyse ne doit rien enregistrer.');
    }

    public function test_xlsx_import_commits_in_batches_into_the_users_clinic_only(): void
    {
        [$otherClinic] = $this->makeClinic('AUTRE');
        $rows = [['nom', 'prenom', 'sexe', 'date_naissance', 'clinic_id', 'ancien_numero']];
        for ($i = 1; $i <= 520; $i++) {
            // 33000 = Excel serial date (07/05/1990); clinic_id column must be ignored.
            $rows[] = ["Nom{$i}", "Prenom{$i}", $i % 2 ? 'F' : 'M', 33000, (string) $otherClinic->id, "OLD-{$i}"];
        }
        $file = UploadedFile::fake()->createWithContent('patients.xlsx', $this->xlsx($rows));

        $preview = $this->actingAs($this->director, 'sanctum')
            ->post('/api/v1/imports/patients/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('valid', 520)
            ->assertJsonPath('preview.0.date_of_birth', '1990-05-07');
        $token = $preview->json('token');

        // Another clinic cannot use the token.
        $this->actingAs($this->makeClinic('PIRATE')[1], 'sanctum')
            ->postJson('/api/v1/imports/patients/commit', ['token' => $token, 'offset' => 0])
            ->assertUnprocessable();

        $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/v1/imports/patients/commit', ['token' => $token, 'offset' => 0])
            ->assertOk()
            ->assertJsonPath('created', 500)
            ->assertJsonPath('processed', 500)
            ->assertJsonPath('done', false);
        $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/v1/imports/patients/commit', ['token' => $token, 'offset' => 500])
            ->assertOk()
            ->assertJsonPath('created', 20)
            ->assertJsonPath('done', true);

        $this->assertSame(520, Patient::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->count());
        $this->assertSame(0, Patient::withoutGlobalScopes()->where('clinic_id', $otherClinic->id)->count());
        $this->assertSame(520, Patient::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->distinct()->count('patient_unique_id'));
        $last = Patient::withoutGlobalScopes()->where('clinic_id', $this->clinic->id)->orderByDesc('patient_unique_id')->first();
        $this->assertSame('IMPCLI-2026-000520', $last->patient_unique_id);
        $this->assertSame('OLD-520', $last->metadata['legacy_id']);

        // The staging file is deleted once finished: replaying is refused.
        $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/v1/imports/patients/commit', ['token' => $token, 'offset' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_plan_patient_limit_is_enforced_and_staff_without_permission_is_refused(): void
    {
        SubscriptionPlan::where('code', 'PRO')->update(['max_patients' => 2]);
        $file = UploadedFile::fake()->createWithContent('modele.csv', app(PatientImporter::class)->template());
        $token = $this->actingAs($this->director, 'sanctum')
            ->post('/api/v1/imports/patients/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->json('token');

        $this->actingAs($this->director, 'sanctum')
            ->postJson('/api/v1/imports/patients/commit', ['token' => $token, 'offset' => 0])
            ->assertUnprocessable();
        $this->assertSame(0, Patient::count());

        $this->actingAs($this->user('RECEPTIONIST', $this->clinic), 'sanctum')
            ->get('/api/v1/imports/patients/template', ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_unsupported_and_header_less_files_are_rejected(): void
    {
        $this->actingAs($this->director, 'sanctum')
            ->post('/api/v1/imports/patients/preview', ['file' => UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->actingAs($this->director, 'sanctum')
            ->post('/api/v1/imports/patients/preview', ['file' => UploadedFile::fake()->createWithContent('x.csv', "a;b;c\n1;2;3")], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_the_medical_history_and_allergies_of_the_old_file_are_imported_and_encrypted(): void
    {
        $csv = "nom;prenom;date_naissance;antecedents;allergies\nBAGA;Mariam;12/04/1980;\"Hypertension; Diabète type 2\";Pénicilline|Arachides\nDJIBRINE;Achta;;;";
        $token = $this->actingAs($this->director, 'sanctum')
            ->post('/api/v1/imports/patients/preview', ['file' => UploadedFile::fake()->createWithContent('historique.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->json('token');
        $this->actingAs($this->director, 'sanctum')->postJson('/api/v1/imports/patients/commit', ['token' => $token, 'offset' => 0])->assertOk();

        $baga = Patient::withoutGlobalScopes()->where('last_name', 'BAGA')->firstOrFail();
        $this->assertSame(['Hypertension', 'Diabète type 2'], $baga->medicalHistory()->pluck('condition')->all());
        $this->assertSame(['Pénicilline', 'Arachides'], $baga->allergies()->pluck('allergen')->all());
        $this->assertNotSame('Hypertension', \Illuminate\Support\Facades\DB::table('patient_medical_history')->where('patient_id', $baga->id)->value('condition'), 'Les antécédents sont chiffrés en base.');
        $this->assertSame(0, Patient::withoutGlobalScopes()->where('last_name', 'DJIBRINE')->first()->medicalHistory()->count());
    }

    /** Builds a minimal .xlsx (shared strings for text, numbers inline). */
    private function xlsx(array $rows): string
    {
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach ($row as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if (is_int($value)) {
                    $cells .= "<c r=\"{$ref}\"><v>{$value}</v></c>";
                } else {
                    $strings[] = htmlspecialchars($value, ENT_XML1);
                    $cells .= "<c r=\"{$ref}\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1)."\">{$cells}</row>";
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Patients" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => "<si><t>{$s}</t></si>", $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function user(string $role, Clinic $clinic): User
    {
        return User::factory()->create([
            'clinic_id' => $clinic->id,
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => 'ACTIVE',
        ]);
    }

    /** @return array{0: Clinic, 1: User} */
    private function makeClinic(string $code): array
    {
        $clinic = Clinic::create([
            'name' => "Clinique {$code}", 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => $code,
            'subscription_plan' => 'PRO', 'admin_email' => strtolower($code).'@example.test', 'status' => 'ACTIVE',
        ]);
        app(SubscriptionService::class)->start($clinic, SubscriptionPlan::where('code', 'PRO')->firstOrFail(), 'MONTHLY', false);

        return [$clinic, $this->user('ADMIN_CLINIC', $clinic)];
    }
}
