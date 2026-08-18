<?php

namespace App\Http\Controllers\Api\Payments;

use App\Http\Controllers\Controller;
use App\Jobs\Payments\ProcessPaymentWebhook;
use App\Services\Payments\PaymentStatuses;
use App\Services\Payments\WebhookProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentWebhookController extends Controller
{
    /**
     * POST /api/payments/webhooks/{provider}
     * Synchronous processing with idempotency; optionally queue via ?async=1
     */
    public function __invoke(Request $request, string $provider, WebhookProcessingService $webhooks): JsonResponse
    {
        $rawBody = $request->getContent() ?: '';
        $payload = $request->all();
        $headers = $request->headers->all();

        if ($request->boolean('async')) {
            ProcessPaymentWebhook::dispatch($provider, $headers, $rawBody, $payload);

            return response()->json(['status' => 'accepted'], 202);
        }

        $event = $webhooks->handle($provider, $headers, $rawBody, $payload);

        return response()->json([
            'status' => $event->status,
            'event_id' => $event->id,
            'payment_id' => $event->payment_id,
        ]);
    }

    /**
     * Dev-only helper: simulate customer approval for stub gateway.
     */
    public function stubComplete(Request $request, string $paymentReference, WebhookProcessingService $webhooks): JsonResponse|Response
    {
        if (! $this->stubCompletionAllowed()) {
            abort(404);
        }

        if ($request->isMethod('GET') && ! $request->boolean('confirm')) {
            $ref = e($paymentReference);

            return response(<<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>CHAPA — Test Payment</title>
              <style>
                body { font-family: system-ui, sans-serif; background: #F7F5F4; margin: 0; padding: 24px; }
                .card { max-width: 420px; margin: 40px auto; background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 8px 24px rgba(125,27,40,0.1); }
                h1 { color: #7D1B28; font-size: 22px; margin: 0 0 8px; }
                p { color: #4A3F42; line-height: 1.5; }
                .ref { background: #F3F0EF; padding: 10px 12px; border-radius: 8px; font-family: monospace; margin: 16px 0; }
                button { width: 100%; background: #7D1B28; color: #fff; border: 0; border-radius: 12px; padding: 14px; font-size: 16px; font-weight: 700; cursor: pointer; }
                .note { font-size: 13px; color: #7A6E71; margin-top: 16px; }
              </style>
            </head>
            <body>
              <div class="card">
                <h1>CHAPA test payment</h1>
                <p>No real money is charged. This simulates approving mobile money in development.</p>
                <div class="ref">{$ref}</div>
                <form method="post" action="">
                  <button type="submit">Confirm test payment</button>
                </form>
                <p class="note">You can also tap <strong>Complete (test / stub)</strong> on the checkout screen in the app.</p>
              </div>
            </body>
            </html>
            HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $payload = [
            'payment_reference' => $paymentReference,
            'status' => PaymentStatuses::SUCCESS,
            'event_id' => 'stub-complete-'.$paymentReference.'-'.now()->timestamp,
            'amount_minor' => null,
        ];

        $raw = json_encode($payload);
        $secret = (string) config('payments.stub.webhook_secret', '');
        $headers = [];
        if ($secret !== '') {
            $headers['x-safarihub-signature'] = [hash_hmac('sha256', $raw, $secret)];
        }

        $event = $webhooks->handle('stub', $headers, $raw ?: '', $payload);

        if (! $request->wantsJson()) {
            $ref = e($paymentReference);

            return response(<<<HTML
            <!DOCTYPE html>
            <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Payment confirmed</title>
            <style>body{font-family:system-ui,sans-serif;background:#E8F5EE;margin:0;padding:24px;text-align:center}
            .card{max-width:420px;margin:40px auto;background:#fff;border-radius:16px;padding:28px}
            h1{color:#1A7A4C}</style></head>
            <body><div class="card"><h1>Payment confirmed</h1><p>Reference {$ref}. You can close this page and return to CHAPA.</p></div></body></html>
            HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return response()->json(['data' => $event]);
    }

    protected function stubCompletionAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }
        if (config('payments.stub.auto_success')) {
            return true;
        }

        return config('payments.default_driver') === 'stub';
    }
}
