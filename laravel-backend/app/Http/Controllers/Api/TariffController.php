<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Clinic;
use App\Services\BillableActs;
use Illuminate\Http\Request;

/** Clinic price list for consultations and the payment term of invoices. */
class TariffController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['data' => BillableActs::tariffs($this->clinicId($request))]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'consultation_general' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'consultation_specialist' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'invoice_due_days' => ['required', 'integer', 'min:0', 'max:90'],
        ]);
        $clinic = Clinic::findOrFail($this->clinicId($request));
        $settings = is_array($clinic->settings) ? $clinic->settings : (json_decode((string) $clinic->settings, true) ?: []);
        $settings['tariffs'] = $validated;
        $clinic->update(['settings' => $settings]);

        return response()->json(['data' => BillableActs::tariffs($clinic->id), 'message' => 'Tarifs enregistrés.']);
    }

    private function clinicId(Request $request): int
    {
        return (int) ($request->attributes->get('clinic_id') ?? $request->user()->clinic_id);
    }
}
