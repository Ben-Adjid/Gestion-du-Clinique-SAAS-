<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Clinical free text must never be readable in the database, only through the application. */
class EncryptionAtRestTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinic = Clinic::create([
            'name' => 'Clinique Chiffrée', 'city' => 'N’Djamena', 'country' => 'Tchad', 'code' => 'ENC',
            'subscription_plan' => 'BASIC', 'admin_email' => 'enc@example.test', 'status' => 'ACTIVE',
        ]);
        $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'patient_unique_id' => 'ENC-2026-000001', 'first_name' => 'Ali', 'last_name' => 'Test']);
    }

    public function test_the_model_stores_clinical_notes_encrypted_and_reads_them_back(): void
    {
        $allergy = $this->patient->allergies()->create(['allergen' => 'Pénicilline', 'severity' => 'HIGH', 'notes' => 'Éruption cutanée à 10 ans']);

        $raw = DB::table('patient_allergies')->where('id', $allergy->id)->value('notes');
        $this->assertNotSame('Éruption cutanée à 10 ans', $raw);
        $this->assertStringNotContainsString('Éruption', (string) $raw);
        $this->assertSame('Éruption cutanée à 10 ans', $allergy->fresh()->notes);
    }

    public function test_the_migration_encrypts_existing_plaintext_once_and_can_be_reverted(): void
    {
        DB::table('patient_allergies')->insert([
            'patient_id' => $this->patient->id, 'allergen' => 'Arachides', 'severity' => 'MEDIUM', 'notes' => 'Donnée ancienne en clair',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_11_06_300000_encrypt_clinical_text_at_rest.php');

        $migration->up();
        $afterUp = DB::table('patient_allergies')->where('allergen', 'Arachides')->value('notes');
        $this->assertNotSame('Donnée ancienne en clair', $afterUp);
        $this->assertSame('Donnée ancienne en clair', PatientAllergy::where('allergen', 'Arachides')->value('notes'));

        // Running it again must not encrypt the ciphertext a second time.
        $migration->up();
        $this->assertSame($afterUp, DB::table('patient_allergies')->where('allergen', 'Arachides')->value('notes'));

        $migration->down();
        $this->assertSame('Donnée ancienne en clair', DB::table('patient_allergies')->where('allergen', 'Arachides')->value('notes'));
    }
}
