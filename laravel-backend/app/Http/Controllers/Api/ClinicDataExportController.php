<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Administrative export of the clinic's own records: one CSV per table, all in one ZIP, so a clinic
 * can leave with its patient list, agenda, invoices and payments. Only the clinic administrator can
 * export. Clinical records (consultations, vitals, prescriptions, lab and imaging) are NOT in this
 * export: medical secrecy reserves them to doctors and nurses.
 *
 * Rows are read straight from the tables (no model hydration, which is what made large clinics slow),
 * so the clinic filter and the removal of deleted rows are written out here, explicitly.
 */
class ClinicDataExportController extends Controller
{
    private const TABLES = [
        'patients',
        'appointments',
        'invoices',
        'invoice_items',
        'payments',
    ];

    public function __invoke(Request $request): BinaryFileResponse
    {
        abort_unless(
            $request->user()->role?->name === 'ADMIN_CLINIC',
            403,
            'Seul l’administrateur de la clinique peut exporter les données de la clinique.',
        );

        // A full clinic export can take longer than the web request limit (30 s on a large clinic): it is allowed to finish.
        set_time_limit(0);

        $clinicId = (int) $request->user()->clinic_id;
        $directory = storage_path('app/exports');
        File::ensureDirectoryExists($directory);
        $zipPath = $directory.DIRECTORY_SEPARATOR.'clinique-'.$clinicId.'-'.now()->format('Ymd-His').'-'.Str::random(6).'.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'L’export est impossible pour le moment. Réessayez dans un instant.');
        }

        $temporary = [];
        foreach (self::TABLES as $table) {
            $path = $directory.DIRECTORY_SEPARATOR.'tmp-'.Str::uuid().'.csv';
            $temporary[] = $path;
            $columns = Schema::getColumnListing($table);

            $handle = fopen($path, 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 byte order mark: Excel reads accents correctly
            fputcsv($handle, $columns, ';');

            // Lines of an invoice belong to the clinic through their invoice (they have no clinic_id of their own).
            $query = DB::table($table)->select("{$table}.*");
            if ($table === 'invoice_items') {
                $query->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->where('invoices.clinic_id', $clinicId);
            } else {
                $query->where("{$table}.clinic_id", $clinicId);
            }
            if (in_array('deleted_at', $columns, true)) {
                $query->whereNull("{$table}.deleted_at");
            }
            // Read by id (no offset): the speed stays flat on very large tables.
            $query->chunkById(2000, function ($rows) use ($handle, $columns): void {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    fputcsv($handle, array_map(fn (string $column) => $this->cell($data[$column] ?? null), $columns), ';');
                }
            }, "{$table}.id", 'id');
            fclose($handle);

            $zip->addFile($path, $table.'.csv');
        }
        $zip->close();

        foreach ($temporary as $path) {
            @unlink($path);
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
