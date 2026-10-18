<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Models\ClinicSubscription;
use App\Models\SubscriptionPlan;
use App\Support\ModuleAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public const SELLABLE_MODULES = [...ModuleAccess::PLAN_GATED, 'hr', 'patient_portal'];

    public function index()
    {
        $plans = SubscriptionPlan::query()->orderBy('monthly_price')->get()->map(fn (SubscriptionPlan $plan) => [
            ...$plan->toArray(),
            'clinics_count' => ClinicSubscription::query()->where('plan_id', $plan->id)->whereNot('status', 'CANCELLED')->count(),
        ]);

        return response()->json(['data' => $plans, 'meta' => ['modules' => self::SELLABLE_MODULES]]);
    }

    public function update(Request $request, SubscriptionPlan $plan)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'monthly_price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999999'],
            'yearly_price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'max_patients' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'modules' => ['sometimes', 'required', 'array', 'min:1'],
            'modules.*' => ['string', Rule::in(self::SELLABLE_MODULES)],
            'trial_days' => ['sometimes', 'required', 'integer', 'min:0', 'max:90'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($validated['modules'])) {
            $validated['modules'] = array_values(array_unique($validated['modules']));
        }

        $plan->update($validated);

        return response()->json(['data' => $plan->fresh(), 'message' => 'Formule mise à jour. Les nouveaux prix s’appliquent aux prochaines factures.']);
    }
}
