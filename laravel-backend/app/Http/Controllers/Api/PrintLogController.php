<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ImagingRequest;
use App\Models\Invoice;
use App\Models\LabRequest;
use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Models\Payroll;
use App\Models\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Every printed document leaves a trace: who printed, which patient or record, when, and why.
 * The browser prints only after this call succeeds, so an unlogged print cannot happen.
 */
class PrintLogController extends Controller
{
    /** kind => [permission required to print, model, resource name in the audit log] */
    private const KINDS = [
        'RECEIPT' => ['manage:payment', Invoice::class],
        'PRESCRIPTION' => ['read:prescription', Prescription::class],
        'LAB_REPORT' => ['read:lab_request', LabRequest::class],
        'IMAGING_REPORT' => ['read:imaging', ImagingRequest::class],
        'CERTIFICATE' => ['read:certificate', MedicalCertificate::class],
        'CLINICAL_FILE' => ['read:dpe', Patient::class],
        'PAYSLIP' => ['access:staff_space', Payroll::class],
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'resource_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], ['reason.min' => 'Indiquez le motif de l’impression (5 caractères minimum).']);

        [$permission, $model] = self::KINDS[$validated['kind']];
        $user = $request->user();
        if (!in_array($permission, \App\Support\ModuleAccess::effectivePermissions($user), true)) {
            return response()->json(['message' => 'Vous n’êtes pas autorisé à imprimer ce document.'], 403);
        }

        // The record must belong to the user's clinic: checked explicitly, not left to a scope.
        $record = $model::query()->withoutGlobalScopes()->where('clinic_id', $user->clinic_id)->find($validated['resource_id']);
        if (!$record) {
            return response()->json(['message' => 'Document introuvable.'], 404);
        }
        // A payslip is printed by its owner, or by the HR team that manages payroll.
        if ($validated['kind'] === 'PAYSLIP' && !in_array('manage:payroll', \App\Support\ModuleAccess::effectivePermissions($user), true)
            && (int) $record->employee?->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Bulletin introuvable.'], 404);
        }
        // The patient is taken from the record itself, never trusted from the browser.
        $patientId = match (true) {
            $record instanceof Patient => $record->id,
            $record instanceof Prescription => $record->consultation()->value('patient_id'),
            $record instanceof Payroll => null,
            default => $record->patient_id,
        };

        AuditLog::create([
            'clinic_id' => $user->clinic_id,
            'user_id' => $user->id,
            'action' => 'PRINT',
            'resource' => $validated['kind'],
            'resource_id' => $record->id,
            'after_data' => ['patient_id' => $patientId, 'reason' => $validated['reason']],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Impression tracée.'], 201);
    }
}
