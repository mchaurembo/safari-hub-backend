<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Services\AuditLogger;
use App\Services\Payments\DTOs\GatewayStatusResult;
use Illuminate\Support\Facades\DB;

class PaymentVerificationService
{
    public function __construct(
        private PaymentManager $manager,
        private AllocationService $allocations,
        private WalletService $wallets,
        private PayableConfirmationService $confirmations,
        private PaymentNotificationService $paymentNotifications,
        private AuditLogger $audit,
    ) {}

    /**
     * Verify with gateway and finalize if SUCCESS. Idempotent.
     */
    public function verifyAndFinalize(Payment $payment, ?PaymentAttempt $attempt = null, array $context = []): Payment
    {
        return DB::transaction(function () use ($payment, $attempt, $context) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->isSuccessful()) {
                return $payment;
            }

            $gateway = $this->manager->forGateway($payment->gateway);
            $result = $gateway->verifyPayment($payment, $attempt, $context);

            $this->recordTransaction($payment, $attempt, 'VERIFY', $result);

            return $this->applyVerifiedStatus($payment, $attempt, $result);
        });
    }

    public function applyVerifiedStatus(Payment $payment, ?PaymentAttempt $attempt, GatewayStatusResult $result): Payment
    {
        if ($payment->isSuccessful()) {
            return $payment;
        }

        if ($result->amountMinor !== null && (int) $result->amountMinor !== (int) $payment->amount_minor) {
            $payment->update([
                'status' => PaymentStatuses::FAILED,
                'failed_at' => now(),
                'failure_reason' => 'Amount mismatch during verification.',
            ]);
            $this->audit->log('payment.amount_mismatch', $payment, null, [
                'expected' => $payment->amount_minor,
                'actual' => $result->amountMinor,
            ]);
            $fresh = $payment->fresh();
            $this->paymentNotifications->notify($fresh, 'failed');

            return $fresh;
        }

        if ($result->currency !== null && strtoupper($result->currency) !== strtoupper((string) $payment->currency)) {
            $payment->update([
                'status' => PaymentStatuses::FAILED,
                'failed_at' => now(),
                'failure_reason' => 'Currency mismatch during verification.',
            ]);
            $fresh = $payment->fresh();
            $this->paymentNotifications->notify($fresh, 'failed');

            return $fresh;
        }

        $status = strtoupper($result->status);

        if (in_array($status, [PaymentStatuses::SUCCESS, 'COMPLETED', 'PAID'], true)) {
            return $this->markSuccess($payment, $attempt, $result);
        }

        if (in_array($status, [PaymentStatuses::FAILED, PaymentStatuses::CANCELLED, PaymentStatuses::EXPIRED], true)) {
            $payment->update([
                'status' => $status,
                'failed_at' => now(),
                'failure_reason' => $result->failureReason,
                'gateway_reference' => $result->gatewayReference ?: $payment->gateway_reference,
            ]);
            $attempt?->update([
                'status' => $status,
                'failure_reason' => $result->failureReason,
                'gateway_reference' => $result->gatewayReference ?: $attempt->gateway_reference,
            ]);

            $fresh = $payment->fresh();
            $this->paymentNotifications->notify(
                $fresh,
                $status === PaymentStatuses::EXPIRED ? 'expired' : 'failed'
            );

            return $fresh;
        }

        $payment->update([
            'status' => in_array($status, [PaymentStatuses::PROCESSING, PaymentStatuses::PENDING], true)
                ? $status
                : PaymentStatuses::PROCESSING,
            'processing_at' => $payment->processing_at ?: now(),
            'gateway_reference' => $result->gatewayReference ?: $payment->gateway_reference,
        ]);

        return $payment->fresh();
    }

    protected function markSuccess(Payment $payment, ?PaymentAttempt $attempt, GatewayStatusResult $result): Payment
    {
        $payment->update([
            'status' => PaymentStatuses::SUCCESS,
            'paid_at' => now(),
            'gateway_reference' => $result->gatewayReference ?: $payment->gateway_reference,
            'transaction_reference' => $result->gatewayReference ?: $payment->transaction_reference,
            'successful_attempt_id' => $attempt?->id ?? $payment->successful_attempt_id,
            'failure_reason' => null,
        ]);

        $attempt?->update([
            'status' => PaymentStatuses::SUCCESS,
            'gateway_reference' => $result->gatewayReference ?: $attempt->gateway_reference,
        ]);

        $this->recordTransaction($payment, $attempt, 'SUCCESS', $result);

        $payment = $payment->fresh();
        $this->allocations->allocateForSuccessfulPayment($payment);
        $this->wallets->creditFromAllocations($payment->fresh(['allocations']));
        $this->confirmations->confirm($payment->fresh());

        $this->audit->log('payment.success', $payment, null, [
            'payment_reference' => $payment->payment_reference,
            'gateway_reference' => $payment->gateway_reference,
        ]);

        $this->paymentNotifications->notify($payment->fresh(), 'successful');

        return $payment->fresh();
    }

    protected function recordTransaction(Payment $payment, ?PaymentAttempt $attempt, string $type, GatewayStatusResult $result): void
    {
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'payment_attempt_id' => $attempt?->id,
            'transaction_type' => $type,
            'amount_minor' => $result->amountMinor ?? $payment->amount_minor,
            'currency' => $result->currency ?? $payment->currency,
            'gateway_reference' => $result->gatewayReference,
            'status' => $result->status,
            'response_payload' => $result->raw,
        ]);
    }
}
