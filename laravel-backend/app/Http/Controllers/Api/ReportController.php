<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function overview(Request $request)
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($validated['from'] ?? now()->subMonths(11)->startOfMonth())->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now())->endOfDay();
        if ($from->diffInDays($to) > 366) {
            return response()->json(['message' => 'La période des rapports ne peut pas dépasser 366 jours.'], 422);
        }

        $clinicId = $request->attributes->get('clinic_id');
        $scope = static fn ($query) => $query->when($clinicId !== null, fn ($scoped) => $scoped->where('clinic_id', $clinicId));

        $appointmentQuery = $scope(Appointment::query());
        $appointmentStatusCounts = $appointmentQuery
            ->whereBetween('appointment_datetime', [$from, $to])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $paidPayments = $scope(Payment::query())
            ->where('status', 'CONFIRMED')
            ->whereBetween('payment_datetime', [$from, $to]);
        $outstandingInvoices = $scope(Invoice::query())
            ->whereIn('status', ['ISSUED', 'OVERDUE']);

        return response()->json([
            'data' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'clinic_count' => $clinicId === null ? Clinic::count() : 1,
                'patients' => [
                    'new' => $scope(Patient::query())->whereBetween('created_at', [$from, $to])->count(),
                    'total' => $scope(Patient::query())->count(),
                ],
                'appointments' => [
                    'total' => $appointmentStatusCounts->sum(),
                    'by_status' => $appointmentStatusCounts,
                ],
                'billing' => [
                    'paid_revenue' => (float) $paidPayments->sum('amount'),
                    'outstanding_amount' => (float) $outstandingInvoices->sum('total_amount'),
                ],
                'active_staff' => $scope(User::query())->where('status', 'ACTIVE')->count(),
            ],
        ]);
    }
}
