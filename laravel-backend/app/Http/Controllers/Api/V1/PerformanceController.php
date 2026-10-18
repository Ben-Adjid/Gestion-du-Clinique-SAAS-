<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PerformanceController extends Controller
{
    public function cacheStats()
    {
        $driver = config('cache.default');

        return response()->json([
            'cache' => [
                'enabled' => Cache::getStore() !== null,
                'driver' => $driver,
                'ttl' => config("cache.stores.{$driver}.ttl"),
            ],
            'pagination' => [
                'default_per_page' => 15,
                'max_per_page' => 100,
            ],
            'stats' => [
                'cached_queries' => Cache::get('query_count', 0),
                'cache_hits' => Cache::get('cache_hits', 0),
                'cache_misses' => Cache::get('cache_misses', 0),
            ],
        ]);
    }

    public function flushCache(Request $request)
    {
        if ($request->user()->role?->name !== 'SUPER_ADMIN') {
            return response()->json(['message' => 'Only a super administrator can flush the shared cache.'], 403);
        }

        Cache::flush();
        return response()->json(['message' => 'Cache flushed successfully']);
    }

    public function databaseIndexes()
    {
        $indexes = DB::select('SHOW INDEXES FROM users');
        return response()->json(['indexes' => $indexes]);
    }

    public function searchPatients(Request $request)
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;
        if (!$clinicId) {
            return response()->json(['data' => []]);
        }

        $query = trim($validated['q']);
        $cacheKey = 'search_patients_'.hash('sha256', "{$clinicId}:".mb_strtolower($query));
        $ttl = (int) config('cache.stores.'.config('cache.default').'.ttl', 3600);
        $results = Cache::remember($cacheKey, $ttl, function () use ($clinicId, $query) {
            return DB::table('patients')
                ->where('clinic_id', $clinicId)
                ->where(function ($patients) use ($query) {
                    $patients->where('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%")
                        ->orWhere('patient_unique_id', 'like', "%{$query}%");
                })
                ->select(['id', 'patient_unique_id', 'first_name', 'last_name'])
                ->limit(20)
                ->get();
        });

        return response()->json(['data' => $results]);
    }

    public function lazyLoadAppointments(Request $request)
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $clinicId = $request->attributes->get('clinic_id') ?? $request->user()->clinic_id;
        if (!$clinicId) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0]]);
        }
        $page = $validated['page'] ?? 1;
        $perPage = $validated['per_page'] ?? 15;

        $appointments = Appointment::query()
            ->where('clinic_id', $clinicId)
            ->with(['patient:id,first_name,last_name,patient_unique_id', 'doctor:id,name'])
            ->orderBy('appointment_datetime')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $appointments->items(),
            'meta' => [
                'current_page' => $appointments->currentPage(),
                'last_page' => $appointments->lastPage(),
                'per_page' => $appointments->perPage(),
                'total' => $appointments->total(),
            ],
        ]);
    }
}
