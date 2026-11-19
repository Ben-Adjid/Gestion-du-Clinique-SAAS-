<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Platform health for the super administrator: database, queue, failed jobs and imaging storage. */
class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        $driver = DB::connection()->getDriverName();

        $started = microtime(true);
        $reachable = DB::select('SELECT 1 AS ok') !== [];
        $latencyMs = round((microtime(true) - $started) * 1000, 1);

        $perClinic = DB::table('imaging_files')
            ->join('imaging_requests', 'imaging_requests.id', '=', 'imaging_files.request_id')
            ->join('clinics', 'clinics.id', '=', 'imaging_requests.clinic_id')
            ->groupBy('clinics.id', 'clinics.name')
            ->orderByRaw('SUM(imaging_files.file_size) DESC')
            ->limit(10)
            ->get(['clinics.id', 'clinics.name', DB::raw('SUM(imaging_files.file_size) AS bytes'), DB::raw('COUNT(imaging_files.id) AS files')])
            ->map(fn ($row) => ['clinic_id' => $row->id, 'name' => $row->name, 'bytes' => (int) $row->bytes, 'files' => (int) $row->files])
            ->values();

        return response()->json(['data' => [
            'database' => [
                'driver' => $driver,
                'reachable' => $reachable,
                'latency_ms' => $latencyMs,
                'size_bytes' => $this->databaseSize($driver),
                'checked_at' => now()->toIso8601String(),
            ],
            'queue' => [
                'connection' => config('queue.default'),
                'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
                'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            ],
            'imaging_storage' => [
                'total_bytes' => (int) DB::table('imaging_files')->sum('file_size'),
                'top_clinics' => $perClinic,
            ],
            'environment' => ['app_env' => config('app.env'), 'debug' => (bool) config('app.debug')],
        ]]);
    }

    private function databaseSize(string $driver): ?int
    {
        if ($driver === 'sqlite') {
            $path = config('database.connections.sqlite.database');

            return is_string($path) && is_file($path) ? (int) filesize($path) : null;
        }
        if ($driver === 'mysql') {
            $size = DB::selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()');

            return (int) ($size->bytes ?? 0);
        }

        return null;
    }
}
