<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Full export of the clinic's own records, in one ZIP with one CSV per table. Only the clinic
 * administrator can export it. Every table that belongs to the clinic is included: directly
 * (clinic_id) or through its parent record (a consultation section belongs to a consultation).
 *
 * Encrypted fields are stored encrypted in the database: those tables are read through their model,
 * so the file holds readable values. Passwords and two-factor secrets are never exported.
 * Rows are read in chunks to keep memory flat.
 */
class ClinicDataExportController extends Controller
{
    /** Technical tables with no business content. */
    private const EXCLUDED_TABLES = ['sync_timestamps', 'communication_queue', 'chatbot_conversations', 'password_histories', 'password_history', 'sessions', 'cache', 'jobs', 'failed_jobs', 'personal_access_tokens'];

    /** Credentials: never written to a file. */
    private const HIDDEN_COLUMNS = ['password', 'remember_token', 'totp_secret', 'totp_recovery_codes'];

    public function __invoke(Request $request): BinaryFileResponse
    {
        abort_unless(
            $request->user()->role?->name === 'ADMIN_CLINIC',
            403,
            'Seul l’administrateur de la clinique peut exporter les données de la clinique.',
        );

        // A full clinic export can take longer than the web request limit: it is allowed to finish.
        set_time_limit(0);

        $clinicId = (int) $request->user()->clinic_id;
        $directory = storage_path('app/exports');
        File::ensureDirectoryExists($directory);
        $zipPath = $directory.DIRECTORY_SEPARATOR.'clinique-'.$clinicId.'-'.now()->format('Ymd-His').'-'.Str::random(6).'.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'L’export est impossible pour le moment. Réessayez dans un instant.');
        }

        $models = $this->modelsByTable();
        $temporary = [];
        foreach ($this->clinicTables() as $table => $scope) {
            $path = $directory.DIRECTORY_SEPARATOR.'tmp-'.Str::uuid().'.csv';
            $temporary[] = $path;
            $columns = array_values(array_diff(Schema::getColumnListing($table), self::HIDDEN_COLUMNS));
            $model = $models[$table] ?? null;
            $encrypted = $model !== null && $this->hasEncryptedFields($model);

            $handle = fopen($path, 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 byte order mark: Excel reads accents correctly
            fputcsv($handle, $columns, ';');

            $constrain = fn ($query) => $this->constrain($query, $table, $scope, $clinicId);
            $write = function ($rows) use ($handle, $columns): void {
                foreach ($rows as $row) {
                    $data = $row instanceof Model ? $row->toArray() : (array) $row;
                    fputcsv($handle, array_map(fn (string $column) => $this->cell($data[$column] ?? null), $columns), ';');
                }
            };

            // Read by id (no offset): the speed stays flat on very large tables.
            if ($encrypted) {
                $query = $model::query()->withoutGlobalScopes();
                $constrain($query);
                $query->chunkById(1000, $write, $model->getQualifiedKeyName(), $model->getKeyName());
            } else {
                $query = DB::table($table)->select("{$table}.*");
                $constrain($query);
                $query->chunkById(2000, $write, "{$table}.id", 'id');
            }
            fclose($handle);

            $zip->addFile($path, $table.'.csv');
        }
        $zip->close();

        foreach ($temporary as $path) {
            @unlink($path);
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * Each table of the clinic with how it belongs to it: directly (its own clinic_id), or through
     * a parent table (for instance invoice_items through invoices).
     *
     * @return array<string, array{column: ?string, parent: ?string, parentColumn: ?string, deleted: bool}>
     */
    private function clinicTables(): array
    {
        $tables = [];
        foreach (Schema::getTableListing() as $table) {
            $table = str_replace('main.', '', (string) $table);
            if (in_array($table, self::EXCLUDED_TABLES, true) || Str::startsWith($table, ['sqlite_', 'migrations'])) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $deleted = in_array('deleted_at', $columns, true);
            if (in_array('clinic_id', $columns, true)) {
                $tables[$table] = ['column' => null, 'parent' => null, 'parentColumn' => null, 'deleted' => $deleted];
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $parent = $foreign['foreign_table'];
                if (in_array('clinic_id', Schema::getColumnListing($parent), true)) {
                    $tables[$table] = ['column' => $foreign['columns'][0], 'parent' => $parent, 'parentColumn' => $foreign['foreign_columns'][0], 'deleted' => $deleted];
                    break;
                }
            }
        }

        return $tables;
    }

    /** Restricts a query to the rows of this clinic, whatever the table. */
    private function constrain($query, string $table, array $scope, int $clinicId): void
    {
        if ($scope['parent'] === null) {
            $query->where("{$table}.clinic_id", $clinicId);
        } else {
            $query->whereIn("{$table}.{$scope['column']}", DB::table($scope['parent'])
                ->select($scope['parentColumn'])
                ->where('clinic_id', $clinicId)
                ->when(in_array('deleted_at', Schema::getColumnListing($scope['parent']), true), fn ($q) => $q->whereNull('deleted_at')));
        }
        if ($scope['deleted']) {
            $query->whereNull("{$table}.deleted_at");
        }
    }

    /** @return array<string, Model> the model of each table, when the application has one */
    private function modelsByTable(): array
    {
        $models = [];
        foreach (glob(app_path('Models').DIRECTORY_SEPARATOR.'*.php') as $file) {
            $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
            if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
                continue;
            }
            $model = new $class();
            $models[$model->getTable()] = $model;
        }

        return $models;
    }

    private function hasEncryptedFields(Model $model): bool
    {
        return collect($model->getCasts())->contains(fn ($cast) => is_string($cast) && Str::startsWith($cast, 'encrypted'));
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_array($value), is_object($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
