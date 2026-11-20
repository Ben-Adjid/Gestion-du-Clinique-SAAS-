<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class SubscriptionPlanSeeder extends Seeder
{
    /** BASIC (Standard): patients, appointments, consultations and vitals, prescriptions, invoices and receipts. */
    public const CORE_MODULES = ['patients', 'appointments', 'consultations', 'prescriptions', 'nursing', 'invoices'];

    /** PRO (VIP): BASIC + hospitalisation and beds, laboratory and imaging, pharmacy, simple operating theatre. */
    public const PRO_MODULES = [...self::CORE_MODULES, 'beds', 'lab', 'imaging', 'pharmacy', 'surgery'];

    /** ENTERPRISE (large hospitals): PRO + specialties and the full surgical circuit, reports, HR, patient portal. */
    public const ENTERPRISE_MODULES = [...self::PRO_MODULES, 'specialties', 'reports', 'hr', 'patient_portal'];

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
                'trial_days' => 90,
            ],
            [
                'code' => 'PRO',
                'name' => 'Pro',
                'description' => 'Clinique avec hospitalisation, lits et salles, laboratoire, imagerie, pharmacie et bloc opératoire simple.',
                'monthly_price' => 150000,
                'yearly_price' => 1500000,
                'max_users' => 40,
                'max_patients' => 20000,
                'modules' => self::PRO_MODULES,
                'trial_days' => 90,
            ],
            [
                'code' => 'ENTERPRISE',
                'name' => 'Enterprise',
                'description' => 'Grand hôpital : multi-spécialités, chefs de service, circuit chirurgical complet, ressources humaines, portail patient.',
                'monthly_price' => 400000,
                'yearly_price' => 4000000,
                'max_users' => null,
                'max_patients' => null,
                'modules' => self::ENTERPRISE_MODULES,
                'trial_days' => 90,
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
