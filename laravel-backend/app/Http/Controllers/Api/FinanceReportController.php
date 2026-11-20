<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Financial dashboard of the clinic: receipts today and this month, split by payment method. */
class FinanceReportController extends Controller
{
    public function paymentsByMethod(Request $request): JsonResponse
    {
        $clinicId = (int) $request->user()->clinic_id;
        $now = Carbon::now();

        return response()->json(['data' => [
            'today' => $this->summary($clinicId, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'month' => $this->summary($clinicId, $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
        ]]);
    }

    /** @return array{total: float, count: int, methods: list<array{method: string, label: string, total: float, count: int}>} */
    private function summary(int $clinicId, Carbon $from, Carbon $to): array
    {
        $rows = Payment::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('status', 'CONFIRMED')
            ->whereBetween('payment_datetime', [$from, $to])
            ->selectRaw('payment_method, SUM(amount) AS total, COUNT(*) AS count')
            ->groupBy('payment_method')
            ->orderByRaw('SUM(amount) DESC')
            ->get();

        $methods = $rows->map(fn ($row) => [
            'method' => $row->payment_method,
            'label' => Payment::METHOD_LABELS[$row->payment_method] ?? $row->payment_method,
            'total' => round((float) $row->total, 2),
            'count' => (int) $row->count,
        ])->values();

        return [
            'total' => round((float) $methods->sum('total'), 2),
            'count' => (int) $methods->sum('count'),
            'methods' => $methods->all(),
        ];
    }
}
