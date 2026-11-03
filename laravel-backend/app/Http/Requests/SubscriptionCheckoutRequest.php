<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Either an invoice to settle, or a plan and billing cycle to buy. */
class SubscriptionCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'saas_invoice_id' => ['required_without:plan_code', 'prohibits:plan_code', 'nullable', 'integer'],
            'plan_code' => ['required_without:saas_invoice_id', 'nullable', 'string', Rule::exists('subscription_plans', 'code')->where('is_active', true)],
            'billing_cycle' => ['required_with:plan_code', 'nullable', Rule::in(['MONTHLY', 'YEARLY'])],
        ];
    }

    public function messages(): array
    {
        return [
            'saas_invoice_id.required_without' => 'Choisissez une facture à régler ou une formule.',
            'saas_invoice_id.prohibits' => 'Réglez une facture ou choisissez une formule, pas les deux.',
            'plan_code.exists' => 'Cette formule n’est pas disponible.',
        ];
    }
}
