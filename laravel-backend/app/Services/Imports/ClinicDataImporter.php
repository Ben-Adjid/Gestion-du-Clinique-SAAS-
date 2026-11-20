<?php

namespace App\Services\Imports;

use App\Models\ClinicImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Takes over a clinic's data from the archive format of the platform: one CSV per table (';' separator),
 * all in one ZIP. Two passes:
 *  - check(): reads the whole archive without writing anything: missing fields, duplicates, row counts.
 *  - run(): writes the rows table by table, in dependency order, by batches in one transaction each,
 *    and links every row to its new id.
 *
 * Duplicates are never overwritten: a patient already present (same n° de dossier, or same name, phone
 * and birth date) is reused, and the rows that belong to the source record are attached to it.
 * Users are not imported: the columns the database requires take the importing administrator, and the
 * report counts them so that they can be assigned afterwards.
 */
class ClinicDataImporter
{
    /** Tables imported, in dependency order (a parent is written before its children). */
    private const ORDER = ['patients', 'appointments', 'invoices', 'invoice_items', 'payments'];

    /** Foreign keys re-linked to the new ids: column => parent table. */
    private const LINKS = [
        'appointments' => ['patient_id' => 'patients'],
        'invoices' => ['patient_id' => 'patients'],
        'invoice_items' => ['invoice_id' => 'invoices'],
        'payments' => ['invoice_id' => 'invoices'],
    ];

    /** Columns that must be filled for a row to be written. */
    private const REQUIRED = [
        'patients' => ['first_name', 'last_name'],
        'appointments' => ['patient_id', 'appointment_datetime'],
        'invoices' => ['patient_id', 'invoice_number', 'total_amount'],
        'invoice_items' => ['invoice_id', 'description', 'amount'],
        'payments' => ['invoice_id', 'amount', 'payment_method'],
    ];

    /** Columns that point at users, who are not imported. */
    private const USER_COLUMNS = ['doctor_id', 'created_by', 'cancelled_by', 'received_by', 'cheque_status_changed_by', 'user_id'];

    /** Among them, the ones the database requires: they take the importing administrator for now. */
    private const REQUIRED_USER_COLUMNS = ['doctor_id', 'received_by', 'created_by'];

    private const BATCH = 1000;
    private const MAX_SAMPLES = 50;

    private int $usersToAssign = 0;

    /** @var array<string, array<string, int>> source id => new id, per parent table */
    private array $links = [];

    /** @var array<string, int> natural key => existing id in this clinic */
    private array $existing = [];

    /** @var array<string, bool> keys already seen in the archive (duplicates inside the file) */
    private array $seen = [];

    public function check(ClinicImport $import): array
    {
        $zip = $this->open($import);
        $report = $this->emptyReport();
        $this->preloadExisting($import);
        $this->seen = [];

        foreach (self::ORDER as $table) {
            if ($zip->locateName($table.'.csv') === false) {
                $report['tables'][$table] = ['present' => false];
                continue;
            }
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
        $this->preloadExisting($import);
        $this->seen = [];

        foreach (self::ORDER as $table) {
            if ($zip->locateName($table.'.csv') === false) {
                $report['tables'][$table] = ['present' => false];
                continue;
            }
            $stats = ['present' => true, 'rows' => 0, 'imported' => 0, 'duplicates' => 0, 'errors' => 0];
            $pending = [];
            $flush = function () use ($import, $table, &$pending, &$stats, &$report) {
                if ($pending !== []) {
                    $this->writeBatch($import, $table, $pending, $stats);
                    $pending = [];
                }
            };

            $this->eachRow($zip, $table, function (array $row, int $line) use (&$pending, &$stats, &$report, $table, $flush) {
                $stats['rows']++;
                if ($this->missingFields($table, $row) !== []) {
                    $stats['errors']++;
                    $this->sample($report, $table, $line, 'Champ obligatoire manquant');

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

    /** Writes one batch in one transaction: links are checked, duplicates reused, the rest inserted. */
    private function writeBatch(ClinicImport $import, string $table, array $batch, array &$stats): void
    {
        DB::transaction(function () use ($import, $table, $batch, &$stats) {
            $maps = [];
            foreach ($batch as [$row, $line]) {
                $linked = $this->linked($table, $row, $stats);
                if ($linked === null) {
                    continue;
                }
                $key = $this->naturalKey($table, $linked);
                $sourceId = (int) ($linked['id'] ?? 0);
                if ($key !== null && isset($this->existing[$key])) {
                    $targetId = $this->existing[$key];
                    $stats['duplicates']++;
                } else {
                    $targetId = DB::table($table)->insertGetId($this->dataFor($import, $table, $linked));
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

    private function open(ClinicImport $import): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open(Storage::path($import->file_path)) !== true) {
            throw new RuntimeException('Archive illisible : ce n’est pas un fichier ZIP valide.');
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
        DB::table('patients')->where('clinic_id', $import->clinic_id)->select('id', 'patient_unique_id', 'first_name', 'last_name', 'phone', 'date_of_birth')
            ->orderBy('id')->chunk(5000, function ($patients) {
                foreach ($patients as $patient) {
                    $key = $this->naturalKey('patients', (array) $patient);
                    $this->existing[$key] = (int) $patient->id;
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

    /** Key that identifies a record in the clinic: a duplicate has the same key. */
    private function naturalKey(string $table, array $row): ?string
    {
        return match ($table) {
            'patients' => ($row['patient_unique_id'] ?? null) !== null
                ? 'pid:'.$row['patient_unique_id']
                : 'name:'.mb_strtolower(($row['first_name'] ?? '').'|'.($row['last_name'] ?? '').'|'.($row['phone'] ?? '').'|'.($row['date_of_birth'] ?? '')),
            'invoices' => ($row['invoice_number'] ?? null) !== null ? 'inv:'.$row['invoice_number'] : null,
            'payments' => ($row['receipt_number'] ?? null) !== null ? 'rec:'.$row['receipt_number'] : null,
            default => null,
        };
    }

    /**
     * Re-links the foreign keys to the new ids. Returns null (and counts the error) when a parent was not imported.
     */
    private function linked(string $table, array $row, array &$stats): ?array
    {
        foreach (self::LINKS[$table] ?? [] as $column => $parent) {
            $sourceParent = $row[$column] ?? null;
            $targetParent = $sourceParent === null ? null : ($this->links[$parent][(int) $sourceParent] ?? null);
            if ($targetParent === null) {
                $stats['errors']++;

                return null;
            }
            $row[$column] = $targetParent;
        }

        return $row;
    }

    /** The values to write: only the columns of the table, never the source id, with this clinic and the stand-ins. */
    private function dataFor(ClinicImport $import, string $table, array $row): array
    {
        $columns = Schema::getColumnListing($table);
        $data = [];
        foreach ($columns as $column) {
            if ($column === 'id' || !array_key_exists($column, $row)) {
                continue;
            }
            $data[$column] = $row[$column];
        }
        if (in_array('clinic_id', $columns, true)) {
            $data['clinic_id'] = $import->clinic_id;
        }
        foreach (self::USER_COLUMNS as $column) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $data[$column] = in_array($column, self::REQUIRED_USER_COLUMNS, true) ? $import->user_id : null;
            if ($data[$column] !== null) {
                $this->usersToAssign++;
            }
        }
        // A payment needs its date: when the source has none, the date of the import is used.
        if ($table === 'payments' && ($data['payment_datetime'] ?? null) === null) {
            $data['payment_datetime'] = now();
        }
        if (in_array('created_at', $columns, true)) {
            $data['created_at'] ??= now();
            $data['updated_at'] ??= now();
        }

        return $data;
    }
}
