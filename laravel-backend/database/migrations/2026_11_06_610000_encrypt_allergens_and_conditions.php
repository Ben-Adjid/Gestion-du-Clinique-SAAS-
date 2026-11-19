<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Allergen names and medical history conditions are encrypted at rest, like the notes. */
return new class extends Migration
{
    private const TARGETS = [
        'patient_allergies' => ['allergen'],
        'patient_medical_history' => ['condition'],
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
