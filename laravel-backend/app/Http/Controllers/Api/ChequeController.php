<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cheque register. A cheque received at the cashier is listed here until the bank cashes it or rejects it.
 * Its details are fixed when it is received, and its status can change only once (cashed or rejected):
 * nothing is deleted or rewritten, so a cheque can always be traced.
 */
class ChequeController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(Payment::CHEQUE_STATUSES)],
        ]);

        $query = Payment::query()
            ->where('payment_method', 'CHEQUE')
            ->with(['invoice:id,invoice_number,patient_id', 'invoice.patient:id,first_name,last_name,patient_unique_id', 'receivedBy:id,name', 'statusChangedBy:id,name'])
            ->orderByDesc('cheque_date')
            ->orderByDesc('id');

        if (isset($validated['status'])) {
            $query->where('cheque_status', $validated['status']);
        }

        $pending = Payment::query()->where('payment_method', 'CHEQUE')->where('cheque_status', 'PENDING');

        return response()->json([
            'data' => $query->paginate(50),
            'totals' => [
                'pending_count' => (clone $pending)->count(),
                'pending_amount' => round((float) (clone $pending)->sum('amount'), 2),
            ],
        ]);
    }

    public function updateStatus(Request $request, Payment $payment)
    {
        if ($payment->payment_method !== 'CHEQUE') {
            return response()->json(['message' => 'Ce paiement n’est pas un chèque.'], 422);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['CASHED', 'REJECTED'])],
            'note' => ['nullable', 'string', 'max:255', 'required_if:status,REJECTED'],
        ], [
            'status.in' => 'Indiquez si le chèque est encaissé ou rejeté.',
            'note.required_if' => 'Indiquez le motif du rejet (ex. provision insuffisante).',
        ]);

        // Only one change is allowed: a cashed or rejected cheque is closed for good.
        if ($payment->cheque_status !== 'PENDING') {
            return response()->json([
                'message' => $payment->cheque_status === 'CASHED'
                    ? 'Ce chèque est déjà encaissé : son état ne peut plus être modifié.'
                    : 'Ce chèque a déjà été rejeté : son état ne peut plus être modifié.',
            ], 422);
        }

        $payment->update([
            'cheque_status' => $validated['status'],
            'cheque_status_changed_at' => now(),
            'cheque_status_changed_by' => $request->user()->id,
            'cheque_status_note' => $validated['note'] ?? null,
        ]);

        return response()->json([
            'data' => $payment->fresh()->load(['invoice:id,invoice_number,patient_id', 'receivedBy:id,name', 'statusChangedBy:id,name']),
            'message' => $validated['status'] === 'CASHED' ? 'Chèque marqué comme encaissé.' : 'Chèque marqué comme rejeté.',
        ]);
    }
}
