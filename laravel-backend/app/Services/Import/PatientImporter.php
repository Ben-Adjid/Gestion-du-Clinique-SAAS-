<?php

namespace App\Services\Import;

use App\Models\Patient;
use App\Services\PatientNumbering;
use App\Support\PlanLimits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Migration of existing patient files from CSV or Excel, in two steps:
 *  1. analyse(): every line is validated and normalised; valid lines are stored in a private
 *     staging file and a report of rejected lines is returned with a preview;
 *  2. commit(): the staging file is inserted batch by batch (one call per batch, so the client
 *     can show a progress bar), always into the clinic of the user who uploaded the file.
 */
class PatientImporter
{
    public const MAX_ROWS = 20000;
    public const BATCH_SIZE = 500;
    private const DISK = 'local';

    /** Column => accepted header names (normalised: lowercase ASCII, words joined by "_"). */
    public const COLUMNS = [
        'last_name' => ['nom', 'nom_de_famille', 'last_name', 'lastname', 'surname'],
        'first_name' => ['prenom', 'prenoms', 'first_name', 'firstname', 'given_name'],
        'gender' => ['sexe', 'genre', 'gender', 'sex'],
        'date_of_birth' => ['date_de_naissance', 'date_naissance', 'naissance', 'date_of_birth', 'birth_date', 'dob'],
        'phone' => ['telephone', 'tel', 'phone', 'mobile', 'portable'],
        'email' => ['email', 'e_mail', 'courriel', 'mail'],
        'national_id' => ['piece_identite', 'numero_piece_identite', 'cni', 'n_cni', 'national_id', 'id_number'],
        'marital_status' => ['situation_familiale', 'situation_matrimoniale', 'marital_status'],
        'profession' => ['profession', 'metier', 'occupation'],
        'address' => ['adresse', 'quartier', 'address'],
        'city' => ['ville', 'city'],
        'blood_group' => ['groupe_sanguin', 'blood_group', 'blood_type'],
        'legacy_id' => ['ancien_numero', 'ancien_dossier', 'numero_dossier', 'legacy_id', 'old_id'],
    ];

    public const TEMPLATE_HEADERS = ['nom', 'prenom', 'sexe', 'date_naissance', 'telephone', 'email', 'piece_identite', 'situation_familiale', 'profession', 'adresse', 'ville', 'groupe_sanguin', 'ancien_numero'];

    public function __construct(private readonly SpreadsheetReader $reader)
    {
    }

    /**
     * @return array{token: string, total: int, valid: int, rejected: int, columns: array<string, string>, preview: list<array<string, mixed>>, errors: list<array{line: int, field: string, message: string, value: string}>}
     */
    public function analyse(string $path, string $extension, string $fileName, int $clinicId, int $userId): array
    {
        $map = null;
        $valid = [];
        $errors = [];
        $total = 0;
        $seenIdentities = [];
        $seenNationalIds = [];

        foreach ($this->reader->rows($path, $extension) as $line => $cells) {
            if ($map === null) {
                $map = $this->headerMap($cells);
                continue;
            }
            if (++$total > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'Le fichier dépasse '.number_format(self::MAX_ROWS, 0, ',', ' ').' lignes : découpez-le en plusieurs fichiers.']);
            }
            $raw = [];
            foreach ($map as $column => $index) {
                $raw[$column] = trim((string) ($cells[$index] ?? ''));
            }
            [$row, $rowErrors] = $this->validateRow($raw);

            if ($rowErrors === []) {
                $identity = Str::lower(Str::ascii($row['last_name'].'|'.$row['first_name'].'|'.($row['date_of_birth'] ?? '').'|'.preg_replace('/\D/', '', (string) $row['phone'])));
                if (isset($seenIdentities[$identity])) {
                    $rowErrors[] = ['field' => 'last_name', 'message' => "Doublon de la ligne {$seenIdentities[$identity]} dans le fichier.", 'value' => "{$row['last_name']} {$row['first_name']}"];
                } elseif ($row['national_id'] !== null && isset($seenNationalIds[$row['national_id']])) {
                    $rowErrors[] = ['field' => 'national_id', 'message' => "Pièce d’identité déjà présente ligne {$seenNationalIds[$row['national_id']]}.", 'value' => $row['national_id']];
                } else {
                    $seenIdentities[$identity] = $line;
                    if ($row['national_id'] !== null) {
                        $seenNationalIds[$row['national_id']] = $line;
                    }
                }
            }

            if ($rowErrors === []) {
                $valid[] = ['line' => $line, ...$row];
            } elseif (count($errors) < 1000) {
                foreach ($rowErrors as $error) {
                    $errors[] = ['line' => $line, ...$error];
                }
            }
        }

        if ($map === null) {
            throw ValidationException::withMessages(['file' => 'Le fichier est vide.']);
        }
        $valid = $this->removeExisting($valid, $clinicId, $errors);

        $token = (string) Str::uuid();
        Storage::disk(self::DISK)->put($this->stagingPath($clinicId, $token), json_encode([
            'clinic_id' => $clinicId,
            'user_id' => $userId,
            'file_name' => $fileName,
            'created_at' => now()->toIso8601String(),
            'rows' => $valid,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        usort($errors, fn (array $a, array $b) => $a['line'] <=> $b['line']);

        return [
            'token' => $token,
            'total' => $total,
            'valid' => count($valid),
            'rejected' => $total - count($valid),
            'columns' => array_map(fn (int $index) => (string) $index, $map),
            'preview' => array_slice($valid, 0, 50),
            'errors' => $errors,
        ];
    }

    /**
     * Inserts the next batch of a staged import.
     *
     * @return array{processed: int, created: int, skipped: int, total: int, done: bool, skipped_lines: list<array{line: int, message: string}>}
     */
    public function commit(string $token, int $offset, int $clinicId, int $userId): array
    {
        $path = $this->stagingPath($clinicId, $token);
        if (!Str::isUuid($token) || !Storage::disk(self::DISK)->exists($path)) {
            throw ValidationException::withMessages(['token' => 'Import introuvable ou expiré : analysez de nouveau le fichier.']);
        }
        $staged = json_decode((string) Storage::disk(self::DISK)->get($path), true, flags: JSON_THROW_ON_ERROR);
        if ((int) $staged['clinic_id'] !== $clinicId || (int) $staged['user_id'] !== $userId) {
            throw ValidationException::withMessages(['token' => 'Cet import appartient à un autre utilisateur.']);
        }
        $rows = $staged['rows'];
        $batch = array_slice($rows, $offset, self::BATCH_SIZE);
        $created = 0;
        $skippedLines = [];

        if ($batch !== []) {
            PlanLimits::assertCanAddPatient($clinicId, count($batch));
            DB::transaction(function () use ($batch, $clinicId, &$created, &$skippedLines) {
                // Lines that became duplicates since the analysis (another import, a registration…).
                $nationalIds = array_values(array_filter(array_column($batch, 'national_id')));
                $taken = $nationalIds === [] ? [] : array_flip(Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at')->whereIn('national_id', $nationalIds)->pluck('national_id')->all());
                $insertable = [];
                foreach ($batch as $row) {
                    if ($row['national_id'] !== null && isset($taken[$row['national_id']])) {
                        $skippedLines[] = ['line' => (int) $row['line'], 'message' => 'Pièce d’identité déjà enregistrée entre-temps.'];
                        continue;
                    }
                    $insertable[] = $row;
                }
                if ($insertable === []) {
                    return;
                }
                $numbers = PatientNumbering::reserve($clinicId, count($insertable));
                $now = now();
                $records = [];
                foreach ($insertable as $index => $row) {
                    $records[] = [
                        // The clinic is forced from the authenticated user, never read from the file.
                        'clinic_id' => $clinicId,
                        'patient_unique_id' => $numbers[$index],
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'gender' => $row['gender'],
                        'date_of_birth' => $row['date_of_birth'],
                        'phone' => $row['phone'],
                        'email' => $row['email'],
                        'national_id' => $row['national_id'],
                        'marital_status' => $row['marital_status'],
                        'profession' => $row['profession'],
                        'address' => $row['address'],
                        'city' => $row['city'],
                        'blood_group' => $row['blood_group'],
                        'metadata' => json_encode(array_filter(['imported' => true, 'legacy_id' => $row['legacy_id'], 'import_line' => $row['line']]), JSON_UNESCAPED_UNICODE),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($records, 100) as $chunk) {
                    DB::table('patients')->insert($chunk);
                }
                $created = count($records);
            });
        }

        $processed = min(count($rows), $offset + count($batch));
        $done = $processed >= count($rows);
        if ($done) {
            Storage::disk(self::DISK)->delete($path);
        }

        return [
            'processed' => $processed,
            'created' => $created,
            'skipped' => count($skippedLines),
            'total' => count($rows),
            'done' => $done,
            'skipped_lines' => $skippedLines,
        ];
    }

    public function template(): string
    {
        $rows = [
            self::TEMPLATE_HEADERS,
            ['MAHAMAT', 'Halimé', 'F', '12/04/1990', '+235 66 12 34 56', 'halime.mahamat@example.com', 'TD-01-1234567', 'Mariée', 'Enseignante', 'Quartier Moursal', 'N’Djamena', 'O+', 'DOS-2019-0042'],
            ['ABAKAR', 'Moussa', 'M', '03/11/1978', '+235 99 45 67 89', '', '', 'Marié', 'Commerçant', 'Quartier Chagoua', 'N’Djamena', 'A+', 'DOS-2020-0117'],
            ['DJIBRINE', 'Achta', 'F', '2015-06-21', '66 77 88 99', '', '', 'Célibataire', 'Élève', 'Quartier Farcha', 'N’Djamena', '', ''],
        ];
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens accents correctly
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /** @return array<string, int> column => cell index */
    private function headerMap(array $headers): array
    {
        $normalised = array_map(fn ($header) => trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii((string) $header))), '_'), $headers);
        $map = [];
        foreach (self::COLUMNS as $column => $aliases) {
            foreach ($normalised as $index => $header) {
                if (in_array($header, $aliases, true)) {
                    $map[$column] = $index;
                    break;
                }
            }
        }
        $missing = array_diff(['last_name', 'first_name'], array_keys($map));
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'Colonnes obligatoires absentes : « nom » et « prenom ». Utilisez le modèle fourni.']);
        }

        return $map;
    }

    /**
     * @param array<string, string> $raw
     * @return array{0: array<string, ?string>, 1: list<array{field: string, message: string, value: string}>}
     */
    private function validateRow(array $raw): array
    {
        $errors = [];
        $value = fn (string $column) => ($raw[$column] ?? '') === '' ? null : $raw[$column];
        $error = function (string $field, string $message) use (&$errors, $raw) {
            $errors[] = ['field' => $field, 'message' => $message, 'value' => mb_substr((string) ($raw[$field] ?? ''), 0, 120)];
        };

        $row = [
            'last_name' => $value('last_name') !== null ? mb_strtoupper(mb_substr($value('last_name'), 0, 255)) : null,
            'first_name' => $value('first_name') !== null ? mb_convert_case(mb_substr($value('first_name'), 0, 255), MB_CASE_TITLE) : null,
            'gender' => null,
            'date_of_birth' => null,
            'phone' => null,
            'email' => null,
            'national_id' => $value('national_id') !== null ? mb_strtoupper(mb_substr($value('national_id'), 0, 50)) : null,
            'marital_status' => null,
            'profession' => $value('profession') !== null ? mb_substr($value('profession'), 0, 120) : null,
            'address' => $value('address') !== null ? mb_substr($value('address'), 0, 255) : null,
            'city' => $value('city') !== null ? mb_substr($value('city'), 0, 120) : null,
            'blood_group' => null,
            'legacy_id' => $value('legacy_id') !== null ? mb_substr($value('legacy_id'), 0, 60) : null,
        ];

        if ($row['last_name'] === null) {
            $error('last_name', 'Nom obligatoire.');
        }
        if ($row['first_name'] === null) {
            $error('first_name', 'Prénom obligatoire.');
        }

        if (($gender = $value('gender')) !== null) {
            $row['gender'] = match (Str::lower(Str::ascii($gender))) {
                'm', 'h', 'homme', 'masculin', 'male', 'garcon' => 'M',
                'f', 'femme', 'feminin', 'female', 'fille' => 'F',
                'autre', 'other' => 'OTHER',
                default => null,
            };
            if ($row['gender'] === null) {
                $error('gender', 'Sexe non reconnu (M ou F).');
            }
        }

        if (($birth = $value('date_of_birth')) !== null) {
            $row['date_of_birth'] = $this->parseDate($birth);
            if ($row['date_of_birth'] === null) {
                $error('date_of_birth', 'Date invalide (format JJ/MM/AAAA).');
            } elseif ($row['date_of_birth'] > today()->toDateString() || $row['date_of_birth'] < '1900-01-01') {
                $error('date_of_birth', 'Date de naissance impossible.');
                $row['date_of_birth'] = null;
            }
        }

        if (($phone = $value('phone')) !== null) {
            $digits = preg_replace('/\D/', '', $phone);
            if (!preg_match('/^[0-9+\s().\-]{6,30}$/', $phone) || strlen($digits) < 8 || strlen($digits) > 15) {
                $error('phone', 'Téléphone mal formaté (8 à 15 chiffres).');
            } else {
                $row['phone'] = preg_replace('/\s+/', ' ', $phone);
            }
        }

        if (($email = $value('email')) !== null) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
                $error('email', 'Adresse e-mail invalide.');
            } else {
                $row['email'] = Str::lower($email);
            }
        }

        if (($marital = $value('marital_status')) !== null) {
            $row['marital_status'] = match (true) {
                str_starts_with(Str::lower(Str::ascii($marital)), 'celib'), Str::lower($marital) === 'single' => 'SINGLE',
                str_starts_with(Str::lower(Str::ascii($marital)), 'mari'), Str::lower($marital) === 'married' => 'MARRIED',
                str_starts_with(Str::lower(Str::ascii($marital)), 'divor') => 'DIVORCED',
                str_starts_with(Str::lower(Str::ascii($marital)), 'veu'), Str::lower($marital) === 'widowed' => 'WIDOWED',
                default => null,
            };
            if ($row['marital_status'] === null) {
                $error('marital_status', 'Situation familiale non reconnue.');
            }
        }

        if (($blood = $value('blood_group')) !== null) {
            $normalised = strtoupper(str_replace([' ', 'POS', 'NEG'], ['', '+', '-'], strtoupper($blood)));
            if (!in_array($normalised, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)) {
                $error('blood_group', 'Groupe sanguin invalide (ex. O+).');
            } else {
                $row['blood_group'] = $normalised;
            }
        }

        return [$row, $errors];
    }

    /** Accepts JJ/MM/AAAA, JJ-MM-AAAA, AAAA-MM-JJ and Excel date serial numbers. */
    private function parseDate(string $value): ?string
    {
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value) && (float) $value > 1 && (float) $value < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }
        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Drops lines already present in the clinic (same identity document, or same name and
     * date of birth) and reports them.
     *
     * @param list<array<string, mixed>> $valid
     * @param list<array<string, mixed>> $errors
     * @return list<array<string, mixed>>
     */
    private function removeExisting(array $valid, int $clinicId, array &$errors): array
    {
        $kept = [];
        foreach (array_chunk($valid, self::BATCH_SIZE) as $chunk) {
            $nationalIds = array_values(array_filter(array_column($chunk, 'national_id')));
            $existingIds = $nationalIds === [] ? [] : array_flip(Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)
                ->whereNull('deleted_at')->whereIn('national_id', $nationalIds)->pluck('national_id')->all());
            $births = array_values(array_filter(array_column($chunk, 'date_of_birth')));
            $existingPeople = [];
            if ($births !== []) {
                Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->whereNull('deleted_at')
                    ->whereIn(DB::raw('DATE(date_of_birth)'), $births)
                    ->get(['first_name', 'last_name', 'date_of_birth'])
                    ->each(function (Patient $patient) use (&$existingPeople) {
                        $existingPeople[Str::lower(Str::ascii($patient->last_name.'|'.$patient->first_name.'|'.$patient->date_of_birth?->toDateString()))] = true;
                    });
            }
            foreach ($chunk as $row) {
                $person = Str::lower(Str::ascii($row['last_name'].'|'.$row['first_name'].'|'.($row['date_of_birth'] ?? '')));
                if ($row['national_id'] !== null && isset($existingIds[$row['national_id']])) {
                    $errors[] = ['line' => $row['line'], 'field' => 'national_id', 'message' => 'Patient déjà enregistré (même pièce d’identité).', 'value' => $row['national_id']];
                } elseif ($row['date_of_birth'] !== null && isset($existingPeople[$person])) {
                    $errors[] = ['line' => $row['line'], 'field' => 'last_name', 'message' => 'Patient déjà enregistré (même nom et date de naissance).', 'value' => "{$row['last_name']} {$row['first_name']}"];
                } else {
                    $kept[] = $row;
                }
            }
        }

        return $kept;
    }

    private function stagingPath(int $clinicId, string $token): string
    {
        return "imports/{$clinicId}/{$token}.json";
    }
}
