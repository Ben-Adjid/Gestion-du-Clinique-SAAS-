<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->with('user:id,name,email');
        $clinicId = $request->attributes->get('clinic_id');

        if ($clinicId !== null) {
            $query->where('clinic_id', $clinicId);
        } elseif ($request->user()->role?->name !== 'SUPER_ADMIN') {
            return response()->json(['message' => 'Clinic context is required.'], 422);
        }

        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'action' => ['sometimes', 'string', 'max:20'],
            'resource' => ['sometimes', 'string', 'max:100'],
        ]);

        $query
            ->when(isset($validated['from']), fn ($logs) => $logs->whereDate('created_at', '>=', $validated['from']))
            ->when(isset($validated['to']), fn ($logs) => $logs->whereDate('created_at', '<=', $validated['to']))
            ->when(isset($validated['action']), fn ($logs) => $logs->where('action', strtoupper($validated['action'])))
            ->when(isset($validated['resource']), fn ($logs) => $logs->where('resource', strtoupper($validated['resource'])));

        return response()->json($query->latest('created_at')->paginate(50));
    }
}
