<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashClosing;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Daily cash register: each cashier closes their own day by counting the cash in
 * the drawer. The difference with the recorded cash payments is kept on file.
 */
class CashController extends Controller
{
    public function summary(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'cashier_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $date = $validated['date'] ?? today()->toDateString();
        $cashierId = $this->canSeeAll($request) ? ($validated['cashier_id'] ?? null) : $request->user()->id;

        $payments = Payment::query()
            ->where('clinic_id', $clinicId)
            ->where('status', 'CONFIRMED')
            ->whereDate('payment_datetime', $date)
            ->when($cashierId, fn ($query) => $query->where('received_by', $cashierId))
            ->with(['invoice:id,invoice_number,patient_id', 'invoice.patient:id,first_name,last_name,patient_unique_id', 'receivedBy:id,name'])
            ->orderBy('payment_datetime')
            ->get();

        $closings = CashClosing::query()
            ->where('clinic_id', $clinicId)
            ->whereDate('business_date', $date)
            ->when($cashierId, fn ($query) => $query->where('cashier_id', $cashierId))
            ->with('cashier:id,name')
            ->get();

        return response()->json(['data' => [
            'date' => $date,
            'payments' => $payments,
            'totals' => $this->totals($payments),
            'closings' => $closings,
            'closed' => $cashierId !== null && $closings->contains('cashier_id', $cashierId),
            'can_close' => $date === today()->toDateString() && (int) ($cashierId ?? 0) === (int) $request->user()->id,
        ]]);
    }

    public function close(Request $request)
    {
        $clinicId = $this->clinicId($request);
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $closing = DB::transaction(function () use ($clinicId, $validated, $request) {
            $already = CashClosing::query()
                ->where('clinic_id', $clinicId)
                ->where('cashier_id', $request->user()->id)
                ->whereDate('business_date', today())
                ->lockForUpdate()
                ->exists();
            if ($already) {
                abort(422, 'Votre caisse du jour est déjà clôturée.');
            }
            $payments = Payment::query()
                ->where('clinic_id', $clinicId)
                ->where('status', 'CONFIRMED')
                ->where('received_by', $request->user()->id)
                ->whereDate('payment_datetime', today())
                ->whereNull('cash_closing_id')
                ->lockForUpdate()
                ->get();
            $totals = $this->totals($payments);
            $difference = round((float) $validated['counted_cash'] - $totals['CASH'], 2);
            if ($difference !== 0.0 && trim((string) ($validated['notes'] ?? '')) === '') {
                abort(422, 'Un écart de caisse doit être expliqué dans les observations.');
            }

            $closing = CashClosing::create([
                'clinic_id' => $clinicId,
                'cashier_id' => $request->user()->id,
                'business_date' => today()->toDateString(),
                'payments_count' => $payments->count(),
                'expected_cash' => $totals['CASH'],
                'expected_mobile_money' => $totals['MOBILE_MONEY'],
                'expected_other' => $totals['BANK_TRANSFER'] + $totals['STRIPE'],
                'counted_cash' => round((float) $validated['counted_cash'], 2),
                'difference' => $difference,
                'notes' => $validated['notes'] ?? null,
                'closed_at' => now(),
            ]);
            Payment::query()->whereIn('id', $payments->pluck('id'))->update(['cash_closing_id' => $closing->id]);

            return $closing;
        });

        return response()->json([
            'data' => $closing->load('cashier:id,name'),
            'message' => $closing->difference == 0
                ? 'Caisse clôturée : le montant compté correspond aux encaissements.'
                : 'Caisse clôturée avec un écart de '.number_format($closing->difference, 0, ',', ' ').' FCFA.',
        ], 201);
    }

    public function closings(Request $request)
    {
        $clinicId = $this->clinicId($request);

        return response()->json(CashClosing::query()
            ->where('clinic_id', $clinicId)
            ->when(!$this->canSeeAll($request), fn ($query) => $query->where('cashier_id', $request->user()->id))
            ->with('cashier:id,name')
            ->orderByDesc('business_date')
            ->orderByDesc('closed_at')
            ->paginate(30));
    }

    /** @return array{CASH: float, MOBILE_MONEY: float, BANK_TRANSFER: float, STRIPE: float, total: float, count: int} */
    private function totals($payments): array
    {
        $byMethod = $payments->groupBy('payment_method')->map(fn ($group) => round((float) $group->sum('amount'), 2));

        return [
            'CASH' => $byMethod->get('CASH', 0.0),
            'MOBILE_MONEY' => $byMethod->get('MOBILE_MONEY', 0.0),
            'BANK_TRANSFER' => $byMethod->get('BANK_TRANSFER', 0.0),
            'STRIPE' => $byMethod->get('STRIPE', 0.0),
            'total' => round((float) $payments->sum('amount'), 2),
            'count' => $payments->count(),
        ];
    }

    private function canSeeAll(Request $request): bool
    {
        return in_array($request->user()->role?->name, ['ADMIN_CLINIC'], true);
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
