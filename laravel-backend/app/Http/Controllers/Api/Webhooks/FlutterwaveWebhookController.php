<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFlutterwaveTransaction;
use App\Services\Payments\FlutterwaveClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Flutterwave v3 webhook. The body is never trusted: after checking the "verif-hash"
 * signature, only the transaction id is kept and the transaction is verified again with
 * the Flutterwave API before anything is applied (see SubscriptionCheckout::process()).
 */
class FlutterwaveWebhookController extends Controller
{
    public function __invoke(Request $request, FlutterwaveClient $flutterwave)
    {
        if (!$flutterwave->validSignature($request->header('verif-hash'))) {
            Log::warning('Webhook Flutterwave rejeté : signature invalide.', ['ip' => $request->ip()]);

            return response()->json(['status' => 'error', 'message' => 'Signature invalide.'], 401);
        }

        $event = (string) ($request->input('event') ?? $request->input('event.type') ?? '');
        $transactionId = $request->input('data.id');
        if ($event === 'charge.completed' && is_numeric($transactionId) && (int) $transactionId > 0) {
            ProcessFlutterwaveTransaction::dispatch((int) $transactionId);
        }

        return response()->json(['status' => 'success']);
    }
}
