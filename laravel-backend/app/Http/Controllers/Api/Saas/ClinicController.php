<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClinicController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in(['ACTIVE', 'INACTIVE', 'SUSPENDED'])],
            'plan' => ['sometimes', 'nullable', 'string', 'max:30'],
            'subscription_status' => ['sometimes', 'nullable', Rule::in(['TRIAL', 'ACTIVE', 'PAST_DUE', 'SUSPENDED', 'CANCELLED'])],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        $clinics = Clinic::query()
            ->with('subscription.plan:id,code,name')
            ->withCount(['users as active_users_count' => fn ($users) => $users->where('status', 'ACTIVE')])
            ->withSum(['saasInvoices as unpaid_amount' => fn ($invoices) => $invoices->whereIn('status', ['PENDING', 'OVERDUE'])], 'amount')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('admin_email', 'like', "%{$search}%")))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['plan'] ?? null, fn ($query, $plan) => $query->whereHas('subscription.plan', fn ($plans) => $plans->where('code', $plan)))
            ->when($validated['subscription_status'] ?? null, fn ($query, $status) => $query->whereHas('subscription', fn ($subs) => $subs->where('status', $status)))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($clinics);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:clinics,name'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash:ascii', 'unique:clinics,code'],
            'city' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'plan_code' => ['required', Rule::exists('subscription_plans', 'code')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['MONTHLY', 'YEARLY'])],
            // The clinic must accept the terms of use (CGU) before it is created.
            'accepted_terms' => ['required', 'accepted'],
            // Every new clinic starts with the free trial of its plan (3 months); no longer a choice.
            'with_trial' => ['sometimes', 'boolean'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
        ], [
            'code.alpha_dash' => 'Le code ne doit contenir que des lettres, chiffres, tirets ou soulignés (sans espace ni accent).',
            'admin_email.unique' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
        ]);

        $plan = SubscriptionPlan::query()->where('code', $validated['plan_code'])->firstOrFail();

        $clinic = DB::transaction(function () use ($validated, $plan, $request) {
            $clinic = Clinic::create([
                'name' => $validated['name'],
                'code' => strtoupper($validated['code']),
                'city' => $validated['city'],
                'country' => $validated['country'],
                'subscription_plan' => $plan->code,
                'admin_email' => $validated['admin_email'],
                'admin_phone' => $validated['admin_phone'] ?? null,
                'settings' => ['address' => $validated['address'] ?? null, 'phone' => $validated['phone'] ?? null],
                'terms_version' => config('clinic.terms_version'),
                'terms_accepted_at' => now(),
                'status' => 'ACTIVE',
            ]);
            User::create([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'phone' => $validated['admin_phone'] ?? null,
                'password' => Str::password(24),
                'clinic_id' => $clinic->id,
                'role_id' => Role::query()->where('name', 'ADMIN_CLINIC')->value('id'),
                'status' => 'ACTIVE',
            ]);
            $this->subscriptions->start($clinic, $plan, $validated['billing_cycle'], true, $request->user());

            return $clinic;
        });

        Password::sendResetLink(['email' => $validated['admin_email']]);

        return response()->json([
            'data' => $this->details($clinic->fresh()),
            'message' => "Clinique créée. Un e-mail d’activation a été envoyé à {$validated['admin_email']} pour choisir son mot de passe.",
        ], 201);
    }

    public function show(Clinic $clinic)
    {
        return response()->json(['data' => $this->details($clinic)]);
    }

    public function update(Request $request, Clinic $clinic)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('clinics', 'name')->ignore($clinic->id)],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'country' => ['sometimes', 'required', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'admin_email' => ['sometimes', 'required', 'email', 'max:255'],
            'admin_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $settings = $clinic->settings ?? [];
        foreach (['address', 'phone'] as $key) {
            if (array_key_exists($key, $validated)) {
                $settings[$key] = $validated[$key];
                unset($validated[$key]);
            }
        }
        $clinic->update([...$validated, 'settings' => $settings]);

        return response()->json(['data' => $this->details($clinic->fresh()), 'message' => 'Clinique mise à jour.']);
    }

    public function suspend(Request $request, Clinic $clinic)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->subscriptions->suspend($clinic, $validated['reason'], $request->user());

        return response()->json(['data' => $this->details($clinic->fresh()), 'message' => 'Clinique suspendue : son personnel ne peut plus se connecter.']);
    }

    public function reactivate(Request $request, Clinic $clinic)
    {
        $this->subscriptions->reactivate($clinic, $request->user());

        return response()->json(['data' => $this->details($clinic->fresh()), 'message' => 'Clinique réactivée.']);
    }

    public function changePlan(Request $request, Clinic $clinic)
    {
        $validated = $request->validate([
            'plan_code' => ['required', Rule::exists('subscription_plans', 'code')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['MONTHLY', 'YEARLY'])],
        ]);
        $subscription = $clinic->subscription ?? abort(422, 'Cette clinique n’a pas d’abonnement.');
        $plan = SubscriptionPlan::query()->where('code', $validated['plan_code'])->firstOrFail();
        $this->subscriptions->changePlan($subscription, $plan, $validated['billing_cycle'], $request->user());

        return response()->json(['data' => $this->details($clinic->fresh()), 'message' => "Formule changée pour {$plan->name}."]);
    }

    public function cancelSubscription(Request $request, Clinic $clinic)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $subscription = $clinic->subscription ?? abort(422, 'Cette clinique n’a pas d’abonnement.');
        $this->subscriptions->cancel($subscription, $validated['reason'], $request->user());

        return response()->json(['data' => $this->details($clinic->fresh()), 'message' => 'Résiliation programmée à la fin de la période en cours.']);
    }

    public function resumeSubscription(Request $request, Clinic $clinic)
    {
        $subscription = $clinic->subscription ?? abort(422, 'Cette clinique n’a pas d’abonnement.');
        $this->subscriptions->resume($subscription, $request->user());

        return response()->json(['data' => $this->details($clinic->fresh()), 'message' => 'Résiliation annulée.']);
    }

    private function details(Clinic $clinic): array
    {
        $clinic->load([
            'subscription.plan',
            'saasInvoices' => fn ($invoices) => $invoices->with('payments.declarer:id,name')->latest('issued_at')->limit(24),
            'subscriptionEvents' => fn ($events) => $events->with('user:id,name')->latest('created_at')->latest('id')->limit(50),
        ]);
        $plan = $clinic->subscription?->plan;

        return [
            ...$clinic->only(['id', 'name', 'code', 'city', 'country', 'admin_email', 'admin_phone', 'status', 'created_at']),
            'address' => $clinic->settings['address'] ?? null,
            'phone' => $clinic->settings['phone'] ?? null,
            'subscription' => $clinic->subscription,
            'usage' => [
                'active_users' => PlanLimits::activeStaff($clinic->id),
                'max_users' => $plan?->max_users,
                'patients' => Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereNull('deleted_at')->count(),
                'max_patients' => $plan?->max_patients,
                // Disk space used by the clinic's imaging files (exams, DICOM or scanned reports)
                'imaging_storage_bytes' => (int) \Illuminate\Support\Facades\DB::table('imaging_files')
                    ->join('imaging_requests', 'imaging_requests.id', '=', 'imaging_files.request_id')
                    ->where('imaging_requests.clinic_id', $clinic->id)
                    ->sum('imaging_files.file_size'),
            ],
            'administrators' => User::query()
                ->where('clinic_id', $clinic->id)
                ->whereHas('role', fn ($roles) => $roles->where('name', 'ADMIN_CLINIC'))
                ->get(['id', 'name', 'email', 'phone', 'status', 'last_login_at']),
            'invoices' => $clinic->saasInvoices,
            'events' => $clinic->subscriptionEvents,
        ];
    }
}
