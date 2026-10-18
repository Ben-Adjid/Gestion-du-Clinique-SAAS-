<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SubscriptionPlanSeeder extends Seeder
{
    public const CORE_MODULES = ['patients', 'appointments', 'consultations', 'prescriptions', 'nursing', 'invoices'];

    public function run(): void
    {
        $plans = [
            [
                'code' => 'BASIC',
                'name' => 'Basic',
                'description' => 'Cabinet ou petite clinique : dossiers patients, rendez-vous, consultations, ordonnances, soins et facturation.',
                'monthly_price' => 50000,
                'yearly_price' => 500000,
                'max_users' => 10,
                'max_patients' => 2000,
                'modules' => self::CORE_MODULES,
                'trial_days' => 14,
            ],
            [
                'code' => 'PRO',
                'name' => 'Pro',
                'description' => 'Clinique avec laboratoire et imagerie, rapports d’activité.',
                'monthly_price' => 150000,
                'yearly_price' => 1500000,
                'max_users' => 40,
                'max_patients' => 20000,
                'modules' => [...self::CORE_MODULES, 'lab', 'imaging', 'reports'],
                'trial_days' => 14,
            ],
            [
                'code' => 'ENTERPRISE',
                'name' => 'Enterprise',
                'description' => 'Hôpital ou groupe : tous les modules, utilisateurs et patients illimités.',
                'monthly_price' => 400000,
                'yearly_price' => 4000000,
                'max_users' => null,
                'max_patients' => null,
                'modules' => [...self::CORE_MODULES, 'lab', 'imaging', 'reports', 'hr', 'patient_portal'],
                'trial_days' => 30,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::query()->firstOrCreate(['code' => $plan['code']], [...$plan, 'currency' => 'XAF', 'is_active' => true]);
        }

        Clinic::query()->whereDoesntHave('subscription')->each(function (Clinic $clinic) {
            $plan = SubscriptionPlan::query()->where('code', $clinic->subscription_plan)->first()
                ?? SubscriptionPlan::query()->where('code', 'BASIC')->firstOrFail();
            $start = Carbon::today()->startOfMonth();
            ClinicSubscription::create([
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'billing_cycle' => 'MONTHLY',
                'status' => $clinic->status === 'SUSPENDED' ? 'SUSPENDED' : 'ACTIVE',
                'price' => $plan->monthly_price,
                'current_period_start' => $start,
                'current_period_end' => $start->copy()->endOfMonth(),
                'grace_days' => (int) config('clinic.saas_payment_terms_days'),
            ]);
        });
    }
}
