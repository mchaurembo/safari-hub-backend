<?php

namespace App\Services\Payments\Gateways;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Refund;
use App\Services\Payments\DTOs\GatewayInitializeResult;
use App\Services\Payments\DTOs\GatewayPayoutResult;
use App\Services\Payments\DTOs\GatewayStatusResult;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentStatuses;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Generic HTTPS payment provider adapter.
 * Configure PAYMENT_API_URL + keys — map provider-specific payloads in a dedicated
 * gateway class when integrating Selcom / Flutterwave / etc.
 *
 * Expected initialize response JSON (flexible):
 *   { "reference": "...", "checkout_url": "...", "status": "PENDING" }
 */
class HttpPaymentGateway implements PaymentGatewayInterface
{
    public function code(): string
    {
        return 'http';
    }

    public function initializePayment(Payment $payment, PaymentAttempt $attempt, array $context = []): GatewayInitializeResult
    {
        $base = rtrim((string) config('payments.providers.default.api_url'), '/');
        if ($base === '') {
            throw new RuntimeException('PAYMENT_API_URL is not configured.');
        }

        $response = Http::withToken((string) config('payments.providers.default.secret_key'))
            ->acceptJson()
            ->post("{$base}/payments", [
                'merchant_reference' => $payment->payment_reference,
                'amount' => $payment->amount,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'method' => $payment->payment_method,
                'phone' => $context['phone'] ?? $attempt->phone,
                'return_url' => $context['return_url'] ?? null,
                'customer' => [
                    'id' => $payment->payer_id,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('HttpPaymentGateway initialize failed', ['body' => $response->body()]);

            return new GatewayInitializeResult(
                success: false,
                status: PaymentStatuses::FAILED,
                failureReason: 'Gateway initialize failed',
                raw: $response->json(),
            );
        }

        $data = $response->json() ?? [];

        return new GatewayInitializeResult(
            success: true,
            status: strtoupper((string) ($data['status'] ?? PaymentStatuses::PENDING)),
            gatewayReference: $data['reference'] ?? $data['gateway_reference'] ?? null,
            paymentUrl: $data['checkout_url'] ?? $data['payment_url'] ?? null,
            raw: $data,
        );
    }

    public function getPaymentStatus(Payment $payment, ?PaymentAttempt $attempt = null): GatewayStatusResult
    {
        return $this->verifyPayment($payment, $attempt);
    }

    public function verifyPayment(Payment $payment, ?PaymentAttempt $attempt = null, array $context = []): GatewayStatusResult
    {
        $base = rtrim((string) config('payments.providers.default.api_url'), '/');
        $ref = $payment->gateway_reference ?: $payment->payment_reference;

        $response = Http::withToken((string) config('payments.providers.default.secret_key'))
            ->acceptJson()
            ->get("{$base}/payments/{$ref}");

        if (! $response->successful()) {
            return new GatewayStatusResult(
                status: PaymentStatuses::PENDING,
                gatewayReference: $payment->gateway_reference,
                amountMinor: (int) $payment->amount_minor,
                currency: $payment->currency,
                raw: $response->json(),
                failureReason: 'Verify request failed',
            );
        }

        $data = $response->json() ?? [];

        return new GatewayStatusResult(
            status: strtoupper((string) ($data['status'] ?? PaymentStatuses::PENDING)),
            gatewayReference: $data['reference'] ?? $payment->gateway_reference,
            amountMinor: isset($data['amount_minor']) ? (int) $data['amount_minor'] : (int) $payment->amount_minor,
            currency: $data['currency'] ?? $payment->currency,
            raw: $data,
        );
    }

    public function refundPayment(Refund $refund, Payment $payment): GatewayStatusResult
    {
        $base = rtrim((string) config('payments.providers.default.api_url'), '/');
        $response = Http::withToken((string) config('payments.providers.default.secret_key'))
            ->acceptJson()
            ->post("{$base}/refunds", [
                'payment_reference' => $payment->gateway_reference,
                'amount_minor' => $refund->amount_minor,
                'reason' => $refund->reason,
            ]);

        $data = $response->json() ?? [];

        return new GatewayStatusResult(
            status: $response->successful()
                ? strtoupper((string) ($data['status'] ?? PaymentStatuses::SUCCESS))
                : PaymentStatuses::FAILED,
            gatewayReference: $data['reference'] ?? null,
            amountMinor: (int) $refund->amount_minor,
            currency: $refund->currency,
            raw: $data,
            failureReason: $response->successful() ? null : 'Refund failed',
        );
    }

    public function cancelPayment(Payment $payment, ?PaymentAttempt $attempt = null): GatewayStatusResult
    {
        return new GatewayStatusResult(status: PaymentStatuses::CANCELLED);
    }

    public function createPayout(Payout $payout, array $context = []): GatewayPayoutResult
    {
        return new GatewayPayoutResult(
            success: false,
            status: PaymentStatuses::FAILED,
            failureReason: 'Payouts not configured for HttpPaymentGateway yet.',
        );
    }

    public function getPayoutStatus(Payout $payout): GatewayPayoutResult
    {
        return new GatewayPayoutResult(
            success: false,
            status: $payout->status,
            gatewayReference: $payout->gateway_reference,
        );
    }

    public function validateWebhook(array $headers, string $rawBody, array $payload): bool
    {
        $secret = (string) config('payments.providers.default.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $sig = $headers['x-signature'][0] ?? $headers['X-Signature'][0] ?? null;
        if (is_array($sig)) {
            $sig = $sig[0] ?? null;
        }

        return is_string($sig) && hash_equals(hash_hmac('sha256', $rawBody, $secret), $sig);
    }

    public function parseWebhook(array $payload): array
    {
        return [
            'payment_reference' => $payload['merchant_reference'] ?? $payload['payment_reference'] ?? null,
            'gateway_reference' => $payload['reference'] ?? $payload['gateway_reference'] ?? null,
            'event_id' => $payload['event_id'] ?? $payload['id'] ?? null,
            'status' => $payload['status'] ?? null,
            'amount_minor' => isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
            'currency' => $payload['currency'] ?? null,
        ];
    }
}
