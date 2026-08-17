<?php

namespace App\Services\Payments\Gateways;

use App\Helpers\PhoneHelper;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\User;
use App\Services\Payments\DTOs\GatewayInitializeResult;
use App\Services\Payments\DTOs\GatewayPayoutResult;
use App\Services\Payments\DTOs\GatewayStatusResult;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\PaymentMoney;
use App\Services\Payments\PaymentStatuses;
use App\Services\Payments\Selcom\SelcomClient;
use Illuminate\Support\Facades\Log;

/**
 * Selcom checkout — funds settle to the merchant vendor account configured in SELCOM_VENDOR.
 *
 * @see https://developers.selcommobile.com/
 */
class SelcomPaymentGateway implements PaymentGatewayInterface
{
    private const CREATE_ORDER_PATH = '/v1/checkout/create-order-minimal';

    private const WALLET_PAYMENT_PATH = '/v1/checkout/wallet-payment';

    private const ORDER_STATUS_PATH = '/v1/checkout/order-status';

    public function code(): string
    {
        return 'selcom';
    }

    public function initializePayment(Payment $payment, PaymentAttempt $attempt, array $context = []): GatewayInitializeResult
    {
        $client = SelcomClient::fromConfig();
        if (! $client) {
            return new GatewayInitializeResult(
                success: false,
                status: PaymentStatuses::FAILED,
                failureReason: 'Selcom is not configured (SELCOM_VENDOR, SELCOM_API_KEY, SELCOM_API_SECRET).',
            );
        }

        $payment->loadMissing(['payer', 'method']);
        $payer = $payment->payer;
        $orderId = $payment->payment_reference;
        $amountMajor = $this->selcomAmountMajor($payment);
        $buyerPhone = $this->toSelcomMsisdn($context['phone'] ?? $attempt->phone ?? $payer?->phone);
        $webhookUrl = base64_encode(url('/api/payments/webhooks/selcom'));
        $returnUrl = base64_encode($this->resolveReturnUrl($context));

        $orderPayload = [
            'vendor' => $client->vendor(),
            'order_id' => $orderId,
            'buyer_email' => $this->buyerEmail($payer),
            'buyer_name' => $this->buyerName($payer),
            'buyer_phone' => $buyerPhone ?? '255700000000',
            'amount' => $amountMajor,
            'currency' => strtoupper((string) $payment->currency),
            'redirect_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'webhook' => $webhookUrl,
            'buyer_remarks' => 'Safari Hub payment',
            'merchant_remarks' => $orderId,
            'no_of_items' => 1,
        ];

        $orderResponse = $client->post(self::CREATE_ORDER_PATH, $orderPayload);
        if (! $this->isSelcomSuccess($orderResponse)) {
            Log::warning('selcom.create_order_failed', [
                'payment_reference' => $orderId,
                'response' => $orderResponse,
            ]);

            return new GatewayInitializeResult(
                success: false,
                status: PaymentStatuses::FAILED,
                failureReason: $orderResponse['message'] ?? 'Could not create Selcom order.',
                raw: $orderResponse,
            );
        }

        $gatewayReference = (string) ($orderResponse['reference'] ?? '');
        $orderData = $orderResponse['data'][0] ?? [];
        $paymentUrl = $this->decodeMaybeBase64Url($orderData['payment_gateway_url'] ?? null);
        $walletPush = null;

        $isMobileMoney = ($payment->method?->type ?? '') === 'mobile_money';
        if ($isMobileMoney && $buyerPhone) {
            $transId = 'SH'.$attempt->id;
            $walletPayload = [
                'transid' => $transId,
                'order_id' => $orderId,
                'msisdn' => $buyerPhone,
            ];
            $walletPush = $client->post(self::WALLET_PAYMENT_PATH, $walletPayload);
            if (! $this->isSelcomSuccess($walletPush) && ! $this->isSelcomPending($walletPush)) {
                Log::warning('selcom.wallet_push_failed', [
                    'payment_reference' => $orderId,
                    'response' => $walletPush,
                ]);

                return new GatewayInitializeResult(
                    success: false,
                    status: PaymentStatuses::FAILED,
                    gatewayReference: $gatewayReference ?: null,
                    failureReason: $walletPush['message'] ?? 'Could not send mobile money prompt.',
                    raw: ['order' => $orderResponse, 'wallet' => $walletPush],
                );
            }
        }

        return new GatewayInitializeResult(
            success: true,
            status: PaymentStatuses::PENDING,
            gatewayReference: $gatewayReference ?: null,
            paymentUrl: $paymentUrl,
            raw: [
                'driver' => 'selcom',
                'order' => $orderResponse,
                'wallet' => $walletPush,
                'wallet_push_sent' => $walletPush !== null,
            ],
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

        return $this->fetchOrderStatus($payment);
    }

    public function verifyPayment(Payment $payment, ?PaymentAttempt $attempt = null, array $context = []): GatewayStatusResult
    {
        $fromApi = $this->fetchOrderStatus($payment);

        if ($fromApi->status === PaymentStatuses::SUCCESS) {
            return $fromApi;
        }

        $webhook = $context['webhook'] ?? null;
        if (is_array($webhook) && ($webhook['status'] ?? null) === PaymentStatuses::SUCCESS) {
            return new GatewayStatusResult(
                status: PaymentStatuses::SUCCESS,
                gatewayReference: $webhook['gateway_reference'] ?? $fromApi->gatewayReference,
                amountMinor: $webhook['amount_minor'] ?? $fromApi->amountMinor ?? (int) $payment->amount_minor,
                currency: $webhook['currency'] ?? $fromApi->currency ?? $payment->currency,
                raw: $webhook,
            );
        }

        return $fromApi;
    }

    public function refundPayment(Refund $refund, Payment $payment): GatewayStatusResult
    {
        return new GatewayStatusResult(
            status: PaymentStatuses::FAILED,
            raw: ['driver' => 'selcom', 'message' => 'Selcom refunds are not automated yet — process manually in Selcom dashboard.'],
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
            raw: ['driver' => 'selcom', 'message' => 'Selcom outbound payouts are not automated yet.'],
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
        if ((bool) config('payments.selcom.webhook_allow_unsigned', false)) {
            return true;
        }

        $client = SelcomClient::fromConfig();
        if (! $client) {
            return app()->environment(['local', 'testing']);
        }

        return $client->validateWebhookDigest($headers, $payload);
    }

    public function parseWebhook(array $payload): array
    {
        $paymentStatus = strtoupper((string) ($payload['payment_status'] ?? ''));

        return [
            'payment_reference' => $payload['order_id'] ?? null,
            'gateway_reference' => $payload['reference'] ?? null,
            'event_id' => ($payload['transid'] ?? 'selcom').':'.($payload['reference'] ?? md5(json_encode($payload))),
            'status' => $this->mapSelcomPaymentStatus($paymentStatus),
            'amount_minor' => isset($payload['amount']) ? PaymentMoney::toMinor((string) $payload['amount']) : null,
            'currency' => config('payments.default_currency', 'TZS'),
        ];
    }

    private function fetchOrderStatus(Payment $payment): GatewayStatusResult
    {
        $client = SelcomClient::fromConfig();
        if (! $client) {
            return new GatewayStatusResult(
                status: $payment->status ?: PaymentStatuses::PENDING,
                gatewayReference: $payment->gateway_reference,
                amountMinor: (int) $payment->amount_minor,
                currency: $payment->currency,
            );
        }

        $response = $client->get(self::ORDER_STATUS_PATH, [
            'order_id' => $payment->payment_reference,
        ]);

        if (! $this->isSelcomSuccess($response)) {
            return new GatewayStatusResult(
                status: $payment->status ?: PaymentStatuses::PENDING,
                gatewayReference: $payment->gateway_reference,
                amountMinor: (int) $payment->amount_minor,
                currency: $payment->currency,
                raw: $response,
            );
        }

        $row = $response['data'][0] ?? [];
        $status = $this->mapSelcomPaymentStatus((string) ($row['payment_status'] ?? 'PENDING'));
        $amountMinor = isset($row['amount'])
            ? PaymentMoney::toMinor((string) $row['amount'])
            : (int) $payment->amount_minor;

        return new GatewayStatusResult(
            status: $status,
            gatewayReference: $row['reference'] ?? $payment->gateway_reference,
            amountMinor: $amountMinor,
            currency: $payment->currency,
            raw: $response,
        );
    }

    private function mapSelcomPaymentStatus(string $paymentStatus): string
    {
        return match (strtoupper($paymentStatus)) {
            'COMPLETED', 'COMPLETE' => PaymentStatuses::SUCCESS,
            'CANCELLED', 'USERCANCELLED', 'USERCANCELED' => PaymentStatuses::CANCELLED,
            'REJECTED', 'FAIL' => PaymentStatuses::FAILED,
            default => PaymentStatuses::PENDING,
        };
    }

    private function selcomAmountMajor(Payment $payment): int
    {
        return intdiv((int) $payment->amount_minor, 100);
    }

    private function buyerEmail(?User $payer): string
    {
        $email = trim((string) ($payer?->email ?? ''));

        return $email !== '' ? $email : 'payments@safarihub.space';
    }

    private function buyerName(?User $payer): string
    {
        $name = trim((string) ($payer?->name ?? ''));

        return $name !== '' ? $name : 'Safari Hub Customer';
    }

    private function toSelcomMsisdn(?string $phone): ?string
    {
        $normalized = PhoneHelper::normalize($phone);
        if (! $normalized) {
            return null;
        }
        if (str_starts_with($normalized, '0')) {
            return '255'.substr($normalized, 1);
        }
        if (preg_match('/^255\d{9}$/', $normalized)) {
            return $normalized;
        }

        return null;
    }

    private function resolveReturnUrl(array $context): string
    {
        if (! empty($context['return_url'])) {
            return (string) $context['return_url'];
        }

        return rtrim((string) config('app.url'), '/').'/checkout';
    }

    private function decodeMaybeBase64Url(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = base64_decode($value, true);
        if ($decoded !== false && filter_var($decoded, FILTER_VALIDATE_URL)) {
            return $decoded;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    /** @param array<string, mixed>|null $response */
    private function isSelcomSuccess(?array $response): bool
    {
        if (! $response) {
            return false;
        }

        return ($response['result'] ?? '') === 'SUCCESS'
            && ($response['resultcode'] ?? '') === '000';
    }

    /** @param array<string, mixed>|null $response */
    private function isSelcomPending(?array $response): bool
    {
        if (! $response) {
            return false;
        }

        return in_array($response['result'] ?? '', ['PENDING', 'INPROGRESS'], true)
            || ($response['resultcode'] ?? '') === '111';
    }
}
