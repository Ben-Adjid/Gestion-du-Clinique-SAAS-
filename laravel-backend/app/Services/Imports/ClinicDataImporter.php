<?php

namespace App\Services\Imports;

use App\Models\ClinicImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Takes over a clinic's data from the archive format of the platform: one CSV per table (';' separator),
 * all in one ZIP. The tables are read in dependency order (a parent before its children), and every
 * foreign key is re-linked to the new id of its parent.
 *
 *  - check(): reads the whole archive and writes nothing: missing required fields, duplicates, row counts.
 *  - run(): writes the rows by batches, one transaction per batch, and records each source id => new id.
 *
 * Rules:
 *  - a patient, an invoice or a receipt already in the clinic is reused, never overwritten;
 *  - encrypted fields are written through their model, so they are encrypted again;
 *  - users are not imported: the columns that point at a user take the importing administrator when the
 *    database requires them, and stay empty otherwise; the report counts them so that they can be assigned;
 *  - a row whose required parent was not imported is refused and reported.
 */
class ClinicDataImporter
{
    /** Tables never taken over: accounts, sessions, provider and platform billing, and the import itself. */
    private const DENIED = [
        'users', 'user_permissions', 'audit_logs', 'sensitive_access_logs', 'notifications', 'user_notifications',
        'flutterwave_transactions', 'saas_invoices', 'saas_payments', 'clinic_subscriptions', 'subscription_events',
        'password_histories', 'password_history', 'clinic_imports', 'clinic_import_maps', 'sync_timestamps',
        'communication_queue', 'chatbot_conversations', 'chatbot_messages', 'chatbot_context',
        'personal_access_tokens', 'sessions', 'cache', 'jobs', 'failed_jobs', 'migrations',
        // Staff files are tied to accounts, which are not taken over: the staff are set up again in the platform.
        'doctor_profiles', 'employees', 'payroll', 'attendance', 'leaves', 'absence_requests', 'doctor_schedules', 'doctor_availability',
    ];

    /** Columns that are the natural key of a record: a record with the same key already in the clinic is reused. */
    private const NATURAL_KEYS = ['patients', 'invoices', 'payments'];

    /** Columns that must be filled for a row to be written, beyond what the database requires. */
    private const REQUIRED = [
        'patients' => ['first_name', 'last_name'],
        'invoices' => ['invoice_number'],
        'payments' => ['amount', 'payment_method'],
    ];

    private const BATCH = 1000;
    private const MAX_SAMPLES = 50;

    private int $usersToAssign = 0;

    /** @var array<string, array<int, int>> table => (source id => new id) */
    private array $links = [];

    /** @var array<string, int> natural key => existing id in this clinic */
    private array $existing = [];

    /** @var array<string, bool> natural keys seen in the archive (duplicates inside the file) */
    private array $seen = [];

    /** @var array<string, array<string, bool>> "table.column" => values in use on the platform (loaded when needed) */
    private array $taken = [];

    /** @var array<string, Model> table => model, for the tables whose fields are encrypted */
    private array $encryptedModels = [];

    public function check(ClinicImport $import): array
    {
        $zip = $this->open($import);
        $report = $this->emptyReport();
        $this->preloadExisting($import);
        $this->seen = [];

        foreach ($this->plan($zip) as $table) {
            $stats = ['present' => true, 'rows' => 0, 'valid' => 0, 'errors' => 0, 'duplicates' => 0];
            $this->eachRow($zip, $table, function (array $row, int $line) use (&$stats, &$report, $table) {
                $stats['rows']++;
                $missing = $this->missingFields($table, $row);
                if ($missing !== []) {
                    $stats['errors']++;
                    $this->sample($report, $table, $line, 'Champ obligatoire manquant : '.implode(', ', $missing));

                    return;
                }
                $key = $this->naturalKey($table, $row);
                if ($key !== null && (isset($this->seen[$key]) || isset($this->existing[$key]))) {
                    $stats['duplicates']++;
                } else {
                    $stats['valid']++;
                }
                if ($key !== null) {
                    $this->seen[$key] = true;
                }
            });
            $report['tables'][$table] = $stats;
        }
        $zip->close();

        return $report;
    }

    public function run(ClinicImport $import): array
    {
        $zip = $this->open($import);
        $report = $this->emptyReport();
        $this->usersToAssign = 0;
        $this->links = [];
        $this->encryptedModels = $this->modelsByTable();
        $this->preloadExisting($import);
        $this->seen = [];

        foreach ($this->plan($zip) as $table) {
            $stats = ['present' => true, 'rows' => 0, 'imported' => 0, 'duplicates' => 0, 'errors' => 0];
            $pending = [];
            $flush = function () use ($import, $table, &$pending, &$stats, &$report) {
                if ($pending !== []) {
                    $this->writeBatch($import, $table, $pending, $stats, $report);
                    $pending = [];
                }
            };
            $this->eachRow($zip, $table, function (array $row, int $line) use (&$pending, &$stats, &$report, $table, $flush) {
                $stats['rows']++;
                if ($this->missingFields($table, $row) !== []) {
                    $stats['errors']++;
                    $this->sample($report, $table, $line, 'Champ obligatoire manquant : '.implode(', ', $this->missingFields($table, $row)));

                    return;
                }
                $pending[] = [$row, $line];
                if (count($pending) >= self::BATCH) {
                    $flush();
                }
            });
            $flush();
            $report['tables'][$table] = $stats;
        }
        $zip->close();
        $report['to_assign'] = ['links_to_users' => $this->usersToAssign];

        return $report;
    }

    /** Tables present in the archive, in the order they must be written (parents first). */
    private function plan(ZipArchive $zip): array
    {
        $present = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!Str::endsWith($name, '.csv')) {
                continue;
            }
            $table = Str::before($name, '.csv');
            if (!in_array($table, self::DENIED, true) && Schema::hasTable($table) && Schema::hasColumn($table, 'id')) {
                $present[$table] = true;
            }
        }

        $ordered = [];
        $visiting = [];
        $visit = function (string $table) use (&$visit, &$ordered, &$visiting, $present) {
            if (isset($ordered[$table]) || isset($visiting[$table])) {
                return;
            }
            $visiting[$table] = true;
            foreach ($this->foreignKeys($table) as $parent) {
                if ($parent !== $table && isset($present[$parent])) {
                    $visit($parent);
                }
            }
            $ordered[$table] = true;
        };
        foreach (array_keys($present) as $table) {
            $visit($table);
        }

        return array_keys($ordered);
    }

    /** @return list<string> the parent tables referenced by the table */
    private function foreignKeys(string $table): array
    {
        return array_values(array_unique(array_map(fn ($fk) => $fk['foreign_table'], Schema::getForeignKeys($table))));
    }

    /**
     * Writes one batch in one transaction: the links are resolved, the duplicates reused, the rest inserted.
     */
    private function writeBatch(ClinicImport $import, string $table, array $batch, array &$stats, array &$report): void
    {
        DB::transaction(function () use ($import, $table, $batch, &$stats, &$report) {
            $maps = [];
            $columns = $this->columnsOf($table);
            $links = $this->linksOf($table);
            foreach ($batch as [$row, $line]) {
                $values = $this->valuesFor($import, $table, $row, $columns, $links, $stats, $report, $line);
                if ($values === null) {
                    continue;
                }
                $key = $this->naturalKey($table, $row);
                $sourceId = (int) $row['id'];
                // The unique numbers are unique across the platform (not per clinic): a number already used by
                // another clinic is kept under a new suffix, and the renumbering is reported.
                if (!($key !== null && isset($this->existing[$key]))) {
                    foreach ($this->uniqueColumnsOf($table) as $column) {
                        $value = $values[$column] ?? null;
                        if ($value === null) {
                            continue;
                        }
                        if (isset($this->takenValues($table, $column)[$value])) {
                            $value = $value.'-R'.$import->id;
                            $values[$column] = $value;
                            $stats['renumbered'] = ($stats['renumbered'] ?? 0) + 1;
                        }
                        $this->taken[$table.'.'.$column][$value] = true;
                    }
                }
                if ($key !== null && isset($this->existing[$key])) {
                    $targetId = $this->existing[$key];
                    $stats['duplicates']++;
                } else {
                    $targetId = $this->insert($table, $values);
                    if ($key !== null) {
                        $this->existing[$key] = $targetId;
                    }
                    $stats['imported']++;
                }
                $this->links[$table][$sourceId] = $targetId;
                $maps[] = ['clinic_import_id' => $import->id, 'table_name' => $table, 'source_id' => $sourceId, 'target_id' => $targetId];
            }
            foreach (array_chunk($maps, 500) as $chunk) {
                DB::table('clinic_import_maps')->insert($chunk);
            }
        });
    }

    /** Writes one row: through its model when it has encrypted fields, directly otherwise. */
    private function insert(string $table, array $values): int
    {
        if (isset($this->encryptedModels[$table])) {
            $model = $this->encryptedModels[$table]->newInstance();
            $model->forceFill($values)->save();

            return (int) $model->getKey();
        }

        return (int) DB::table($table)->insertGetId($values);
    }

    /**
     * The values of one row, with foreign keys re-linked, the clinic set and the users handled.
     * Returns null when the row is refused (and reported).
     */
    private function valuesFor(ClinicImport $import, string $table, array $row, array $columns, array $links, array &$stats, array &$report, int $line): ?array
    {
        $values = [];
        foreach ($columns as $name => $column) {
            if ($name === 'id' || !array_key_exists($name, $row)) {
                continue;
            }
            $values[$name] = $row[$name];
        }
        if (isset($columns['clinic_id'])) {
            $values['clinic_id'] = $import->clinic_id;
        }

        foreach ($links as $column => $link) {
            $source = $row[$column] ?? null;
            if ($link['parent'] === 'users') {
                // Users are not imported: the administrator stands in when the column is required.
                $values[$column] = $link['required'] ? $import->user_id : null;
                if ($values[$column] !== null) {
                    $this->usersToAssign++;
                }
                continue;
            }
            if ($source === null) {
                $values[$column] = null;
                continue;
            }
            $target = $this->links[$link['parent']][(int) $source] ?? null;
            if ($target === null) {
                $stats['errors']++;
                $this->sample($report, $table, $line, "Lien vers « {$link['parent']} » introuvable (identifiant source {$source})");

                return null;
            }
            $values[$column] = $target;
        }

        // Values the database requires that the archive does not provide.
        foreach ($columns as $name => $column) {
            if ($name === 'id' || ($values[$name] ?? null) !== null || $column['nullable']) {
                continue;
            }
            if (in_array($name, ['created_at', 'updated_at', 'payment_datetime', 'issued_at'], true)) {
                $values[$name] = now();
            }
        }
        if (isset($columns['created_at'])) {
            $values['created_at'] ??= now();
            $values['updated_at'] ??= now();
        }

        // Required by the database but still empty in the archive: the row is refused rather than written incomplete.
        foreach ($columns as $name => $column) {
            if ($name !== 'id' && array_key_exists($name, $values) && $values[$name] === null && !$column['nullable'] && !$column['auto_increment']) {
                $stats['errors']++;
                $this->sample($report, $table, $line, "Valeur obligatoire vide : {$name}");

                return null;
            }
        }

        return $values;
    }

    /** @return list<string> the columns of the table that are unique on their own (except its id) */
    private function uniqueColumnsOf(string $table): array
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $cache[$table] = [];
            foreach (Schema::getIndexes($table) as $index) {
                if ($index['unique'] && !$index['primary'] && count($index['columns']) === 1 && $index['columns'][0] !== 'id') {
                    $cache[$table][] = $index['columns'][0];
                }
            }
        }

        return $cache[$table];
    }

    /** @return array<string, bool> the values of a unique column already used on the platform (loaded once) */
    private function takenValues(string $table, string $column): array
    {
        $key = $table.'.'.$column;
        if (!isset($this->taken[$key])) {
            $this->taken[$key] = [];
            DB::table($table)->whereNotNull($column)->select($column)->orderBy('id')->chunk(10000, function ($rows) use ($key, $column) {
                foreach ($rows as $row) {
                    $this->taken[$key][(string) $row->{$column}] = true;
                }
            });
        }

        return $this->taken[$key];
    }

    /** @return array<string, array{nullable: bool, auto_increment: bool}> the columns of the table */
    private function columnsOf(string $table): array
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            foreach (Schema::getColumns($table) as $column) {
                $cache[$table][$column['name']] = ['nullable' => (bool) $column['nullable'], 'auto_increment' => (bool) ($column['auto_increment'] ?? false)];
            }
        }

        return $cache[$table];
    }

    /**
     * The foreign keys of a table: column => [parent, required]. Only the parents in the import and the users.
     *
     * @return array<string, array{parent: string, required: bool}>
     */
    private function linksOf(string $table): array
    {
        $columns = $this->columnsOf($table);
        $links = [];
        foreach (Schema::getForeignKeys($table) as $fk) {
            $column = $fk['columns'][0];
            $parent = $fk['foreign_table'];
            if ($parent === 'users' || isset($this->importing[$parent])) {
                $links[$column] = ['parent' => $parent, 'required' => !($columns[$column]['nullable'] ?? true)];
            }
        }

        return $links;
    }

    /** @var array<string, bool> the tables written in this run */
    private array $importing = [];

    /** @return array<string, Model> the model of each table whose fields are encrypted */
    private function modelsByTable(): array
    {
        $models = [];
        foreach (glob(app_path('Models').DIRECTORY_SEPARATOR.'*.php') as $file) {
            $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
            if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
                continue;
            }
            $model = new $class();
            $encrypted = collect($model->getCasts())->contains(fn ($cast) => is_string($cast) && Str::startsWith($cast, 'encrypted'));
            if ($encrypted) {
                $models[$model->getTable()] = $model;
            }
        }

        return $models;
    }

    private function open(ClinicImport $import): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open(Storage::path($import->file_path)) !== true) {
            throw new RuntimeException('Archive illisible : ce n’est pas un fichier ZIP valide.');
        }
        // The tables written in this run: the ones present in the archive.
        $this->importing = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $table = Str::before($zip->getNameIndex($i), '.csv');
            if (Str::endsWith($zip->getNameIndex($i), '.csv') && !in_array($table, self::DENIED, true) && Schema::hasTable($table)) {
                $this->importing[$table] = true;
            }
        }

        return $zip;
    }

    private function emptyReport(): array
    {
        return ['tables' => [], 'error_samples' => []];
    }

    /** Loads the records already in this clinic once, so that duplicates are found without a query per row. */
    private function preloadExisting(ClinicImport $import): void
    {
        $this->existing = [];
        $this->taken = [];
        DB::table('patients')->where('clinic_id', $import->clinic_id)->select('id', 'patient_unique_id', 'first_name', 'last_name', 'phone', 'date_of_birth')
            ->orderBy('id')->chunk(5000, function ($patients) {
                foreach ($patients as $patient) {
                    $this->existing[$this->naturalKey('patients', (array) $patient)] = (int) $patient->id;
                }
            });
        DB::table('invoices')->where('clinic_id', $import->clinic_id)->whereNotNull('invoice_number')->select('id', 'invoice_number')
            ->orderBy('id')->chunk(5000, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $this->existing['inv:'.$invoice->invoice_number] = (int) $invoice->id;
                }
            });
        DB::table('payments')->where('clinic_id', $import->clinic_id)->whereNotNull('receipt_number')->select('id', 'receipt_number')
            ->orderBy('id')->chunk(5000, function ($payments) {
                foreach ($payments as $payment) {
                    $this->existing['rec:'.$payment->receipt_number] = (int) $payment->id;
                }
            });
    }

    /** Streams the rows of one CSV without loading the file in memory. */
    private function eachRow(ZipArchive $zip, string $table, callable $callback): void
    {
        $stream = $zip->getStream($table.'.csv');
        $header = null;
        $line = 0;
        while (($cells = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            if ($header === null) {
                $cells[0] = preg_replace('/^\x{FEFF}/u', '', (string) $cells[0]);
                $header = array_map('trim', $cells);

                continue;
            }
            $row = [];
            foreach ($header as $i => $column) {
                $row[$column] = ($cells[$i] ?? '') === '' ? null : $cells[$i];
            }
            $callback($row, $line);
        }
        fclose($stream);
    }

    private function sample(array &$report, string $table, int $line, string $message): void
    {
        if (count($report['error_samples']) < self::MAX_SAMPLES) {
            $report['error_samples'][] = ['table' => $table, 'line' => $line, 'message' => $message];
        }
    }

    private function missingFields(string $table, array $row): array
    {
        return array_values(array_filter(self::REQUIRED[$table] ?? [], fn (string $column) => ($row[$column] ?? null) === null));
    }

    /** Key that identifies a record in the clinic: a record with the same key is reused. Null when there is none. */
    private function naturalKey(string $table, array $row): ?string
    {
        if (!in_array($table, self::NATURAL_KEYS, true)) {
            return null;
        }

        return match ($table) {
            'patients' => ($row['patient_unique_id'] ?? null) !== null
                ? 'pid:'.$row['patient_unique_id']
                : 'name:'.mb_strtolower(($row['first_name'] ?? '').'|'.($row['last_name'] ?? '').'|'.($row['phone'] ?? '').'|'.($row['date_of_birth'] ?? '')),
            'invoices' => ($row['invoice_number'] ?? null) !== null ? 'inv:'.$row['invoice_number'] : null,
            'payments' => ($row['receipt_number'] ?? null) !== null ? 'rec:'.$row['receipt_number'] : null,
            default => null,
        };
    }
}
