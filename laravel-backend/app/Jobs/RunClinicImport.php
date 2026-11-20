<?php

namespace App\Jobs;

use App\Models\ClinicImport;
use App\Services\Imports\ClinicDataImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs one pass of a clinic import in the background: the check (no writes) or the import itself. */
class RunClinicImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;
    public int $tries = 1;

    public function __construct(public int $importId, public string $pass)
    {
    }

    public function handle(ClinicDataImporter $importer): void
    {
        $import = ClinicImport::query()->findOrFail($this->importId);
        try {
            if ($this->pass === 'check') {
                $report = $importer->check($import);
                $import->update(['status' => ClinicImport::CHECKED, 'report' => $report]);

                return;
            }

            $import->update(['status' => ClinicImport::IMPORTING]);
            $report = $importer->run($import);
            $import->update(['status' => ClinicImport::DONE, 'report' => $report, 'finished_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Import de clinique en échec', ['import' => $this->importId, 'message' => $e->getMessage()]);
            $import->update(['status' => ClinicImport::FAILED, 'error' => $e->getMessage(), 'finished_at' => now()]);
        }
    }
}
