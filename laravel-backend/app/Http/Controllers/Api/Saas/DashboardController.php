<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Models\ClinicSubscription;
use App\Models\SaasInvoice;
use App\Models\SaasPayment;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function show()
    {
        $subscriptions = ClinicSubscription::query()->with(['plan:id,code,name', 'clinic:id,name,code,status'])->get();
        $paying = $subscriptions->whereIn('status', ['ACTIVE', 'PAST_DUE']);
        $mrr = round($paying->sum(fn (ClinicSubscription $subscription) => $subscription->monthlyRecurringRevenue()), 2);

        $openInvoices = SaasInvoice::query()->whereIn('status', ['PENDING', 'OVERDUE'])->with('payments')->get();
        $cancelledRecently = $subscriptions->where('status', 'CANCELLED')
            ->filter(fn (ClinicSubscription $subscription) => $subscription->updated_at?->gte(now()->subDays(30)))
            ->count();
        $activeBase = $paying->count() + $cancelledRecently;

        $months = collect(range(5, 0))->map(fn (int $offset) => Carbon::today()->startOfMonth()->subMonths($offset));
        $collectedByMonth = $months->map(fn (Carbon $month) => [
            'month' => $month->format('Y-m'),
            'label' => ucfirst($month->locale('fr')->translatedFormat('M Y')),
            'amount' => (float) SaasPayment::query()
                ->where('status', 'CONFIRMED')
                ->whereBetween('paid_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                ->sum('amount'),
        ]);

        return response()->json(['data' => [
            'mrr' => $mrr,
            'arr' => round($mrr * 12, 2),
            'collected_this_month' => $collectedByMonth->last()['amount'],
            'outstanding_amount' => round($openInvoices->sum('balance'), 2),
            'overdue_amount' => round($openInvoices->where('status', 'OVERDUE')->sum('balance'), 2),
            'pending_payments' => SaasPayment::query()->where('status', 'PENDING')->count(),
            'churn_rate_30d' => $activeBase > 0 ? round($cancelledRecently / $activeBase * 100, 1) : 0.0,
            'clinics_by_status' => [
                'TRIAL' => $subscriptions->where('status', 'TRIAL')->count(),
                'ACTIVE' => $subscriptions->where('status', 'ACTIVE')->count(),
                'PAST_DUE' => $subscriptions->where('status', 'PAST_DUE')->count(),
                'SUSPENDED' => $subscriptions->where('status', 'SUSPENDED')->count(),
                'CANCELLED' => $subscriptions->where('status', 'CANCELLED')->count(),
            ],
            'clinics_by_plan' => $subscriptions->whereNotIn('status', ['CANCELLED'])
                ->groupBy(fn (ClinicSubscription $subscription) => $subscription->plan->name)
                ->map->count(),
            'upcoming_renewals' => $subscriptions
                ->whereIn('status', ['TRIAL', 'ACTIVE', 'PAST_DUE'])
                ->filter(fn (ClinicSubscription $subscription) => $subscription->current_period_end->lte(now()->addDays(30)))
                ->sortBy('current_period_end')
                ->values()
                ->map(fn (ClinicSubscription $subscription) => [
                    'clinic_id' => $subscription->clinic_id,
                    'clinic' => $subscription->clinic->name,
                    'plan' => $subscription->plan->name,
                    'status' => $subscription->status,
                    'period_end' => $subscription->current_period_end->toDateString(),
                    'auto_renew' => $subscription->auto_renew,
                    'price' => $subscription->price,
                ]),
            'collected_by_month' => $collectedByMonth,
        ]]);
    }
}
