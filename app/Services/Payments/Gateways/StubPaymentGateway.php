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
use Illuminate\Support\Str;

/**
 * Development / offline gateway. Never processes real money.
 * Auto-success can be enabled via PAYMENT_STUB_AUTO_SUCCESS=true for local E2E.
 */
class StubPaymentGateway implements PaymentGatewayInterface
{
    public function code(): string
    {
        return 'stub';
    }

    public function initializePayment(Payment $payment, PaymentAttempt $attempt, array $context = []): GatewayInitializeResult
    {
        $ref = 'STUB-'.Str::upper(Str::random(12));
        $auto = (bool) config('payments.stub.auto_success', false);

        return new GatewayInitializeResult(
            success: true,
            status: $auto ? PaymentStatuses::SUCCESS : PaymentStatuses::PENDING,
            gatewayReference: $ref,
            paymentUrl: $auto ? null : url('/api/payments/stub-checkout/'.$payment->payment_reference),
            raw: ['driver' => 'stub', 'auto_success' => $auto],
        );
    }

    public function getPaymentStatus(Payment $payment, ?PaymentAttempt $attempt = null): GatewayStatusResult
    {
        if (PaymentStatuses::isTerminalSuccess($payment->status)) {
            return new GatewayStatusResult(
                status: PaymentStatuses::SUCCESS,
                gatewayReference: $payment->gateway_reference,
                amountMinor: (int) $payment->amount_minor,
                currency: $payment->currency,
            );
        }

        return new GatewayStatusResult(
            status: $payment->status ?: PaymentStatuses::PENDING,
            gatewayReference: $payment->gateway_reference,
            amountMinor: (int) $payment->amount_minor,
            currency: $payment->currency,
        );
    }

    public function verifyPayment(Payment $payment, ?PaymentAttempt $attempt = null, array $context = []): GatewayStatusResult
    {
        return $this->getPaymentStatus($payment, $attempt);
    }

    public function refundPayment(Refund $refund, Payment $payment): GatewayStatusResult
    {
        return new GatewayStatusResult(
            status: PaymentStatuses::SUCCESS,
            gatewayReference: 'STUB-REF-'.Str::upper(Str::random(8)),
            amountMinor: (int) $refund->amount_minor,
            currency: $refund->currency,
            raw: ['driver' => 'stub'],
        );
    }

    public function cancelPayment(Payment $payment, ?PaymentAttempt $attempt = null): GatewayStatusResult
    {
        return new GatewayStatusResult(status: PaymentStatuses::CANCELLED);
    }

    public function createPayout(Payout $payout, array $context = []): GatewayPayoutResult
    {
        return new GatewayPayoutResult(
            success: true,
            status: PaymentStatuses::SUCCESS,
            gatewayReference: 'STUB-PO-'.Str::upper(Str::random(8)),
            raw: ['driver' => 'stub'],
        );
    }

    public function getPayoutStatus(Payout $payout): GatewayPayoutResult
    {
        return new GatewayPayoutResult(
            success: $payout->status === PaymentStatuses::SUCCESS,
            status: $payout->status,
            gatewayReference: $payout->gateway_reference,
        );
    }

    public function validateWebhook(array $headers, string $rawBody, array $payload): bool
    {
        $secret = (string) config('payments.stub.webhook_secret', '');
        if ($secret === '') {
            return app()->environment(['local', 'testing']);
        }

        $sig = $headers['x-safarihub-signature'][0] ?? $headers['X-Safarihub-Signature'][0] ?? null;
        if (is_array($sig)) {
            $sig = $sig[0] ?? null;
        }

        return is_string($sig) && hash_equals(hash_hmac('sha256', $rawBody, $secret), $sig);
    }

    public function parseWebhook(array $payload): array
    {
        return [
            'payment_reference' => $payload['payment_reference'] ?? null,
            'gateway_reference' => $payload['gateway_reference'] ?? null,
            'event_id' => $payload['event_id'] ?? ($payload['id'] ?? null),
            'status' => $payload['status'] ?? null,
            'amount_minor' => isset($payload['amount_minor']) ? (int) $payload['amount_minor'] : null,
            'currency' => $payload['currency'] ?? null,
        ];
    }
}
