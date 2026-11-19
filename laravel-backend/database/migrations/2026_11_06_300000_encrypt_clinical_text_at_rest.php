<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Clinical free text is encrypted at rest with the application key (Eloquent "encrypted" casts).
 * Existing plaintext rows are encrypted here, once; values already encrypted are left alone, so
 * the migration can be re-run safely. Down() restores plaintext.
 */
return new class extends Migration
{
    /** table => clinical text columns */
    private const TARGETS = [
        'consultation_sections' => ['content'],
        'consultations_diagnostics' => ['notes'],
        'prescriptions' => ['notes'],
        'imaging_reports' => ['report_text'],
        'patient_allergies' => ['notes'],
        'patient_medical_history' => ['notes'],
        'medical_certificates' => ['content'],
    ];

    public function up(): void
    {
        $this->convert(encrypt: true);
    }

    public function down(): void
    {
        $this->convert(encrypt: false);
    }

    private function convert(bool $encrypt): void
    {
        foreach (self::TARGETS as $table => $columns) {
            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, $encrypt) {
                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($columns as $column) {
                        $value = $row->{$column};
                        if (!is_string($value) || $value === '') {
                            continue;
                        }
                        $alreadyEncrypted = $this->isEncrypted($value);
                        if ($encrypt && !$alreadyEncrypted) {
                            $updates[$column] = Crypt::encryptString($value);
                        } elseif (!$encrypt && $alreadyEncrypted) {
                            $updates[$column] = Crypt::decryptString($value);
                        }
                    }
                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            }, 'id');
        }
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
