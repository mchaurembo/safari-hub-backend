<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Refund;
use App\Services\Payments\DTOs\GatewayInitializeResult;
use App\Services\Payments\DTOs\GatewayPayoutResult;
use App\Services\Payments\DTOs\GatewayStatusResult;

interface PaymentGatewayInterface
{
    public function code(): string;

    public function initializePayment(Payment $payment, PaymentAttempt $attempt, array $context = []): GatewayInitializeResult;

    public function getPaymentStatus(Payment $payment, ?PaymentAttempt $attempt = null): GatewayStatusResult;

    public function verifyPayment(Payment $payment, ?PaymentAttempt $attempt = null, array $context = []): GatewayStatusResult;

    public function refundPayment(Refund $refund, Payment $payment): GatewayStatusResult;

    public function cancelPayment(Payment $payment, ?PaymentAttempt $attempt = null): GatewayStatusResult;

    public function createPayout(Payout $payout, array $context = []): GatewayPayoutResult;

    public function getPayoutStatus(Payout $payout): GatewayPayoutResult;

    /**
     * Validate inbound webhook authenticity. Return false to reject.
     */
    public function validateWebhook(array $headers, string $rawBody, array $payload): bool;

    /**
     * Extract internal payment reference / gateway reference from webhook payload.
     *
     * @return array{payment_reference?: ?string, gateway_reference?: ?string, event_id?: ?string}
     */
    public function parseWebhook(array $payload): array;
}
