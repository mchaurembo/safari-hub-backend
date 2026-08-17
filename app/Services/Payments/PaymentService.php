<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\CargoRequest;
use App\Models\GarageBooking;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentService
{
    public function __construct(
        private PaymentManager $manager,
        private PaymentReferenceGenerator $references,
        private PaymentVerificationService $verification,
        private PaymentNotificationService $paymentNotifications,
        private AuditLogger $audit,
    ) {}

    /**
     * Create payment + first attempt, initialize gateway. Idempotent via idempotency_key.
     *
     * @param  array{
     *   payer: User,
     *   payable: Model,
     *   amount_major?: string|int|float,
     *   amount_minor?: int,
     *   currency?: string,
     *   method_code: string,
     *   phone?: string,
     *   idempotency_key?: string,
     *   metadata?: array,
     *   module?: string
     * }  $input
     */
    public function createAndInitialize(array $input): Payment
    {
        $payer = $input['payer'];
        $payable = $input['payable'];
        $methodCode = strtoupper((string) $input['method_code']);
        $currency = strtoupper((string) ($input['currency'] ?? config('payments.default_currency', 'TZS')));
        $idempotencyKey = $input['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['method', 'gateway', 'attempts']);
            }
        }

        $amountMinor = isset($input['amount_minor'])
            ? (int) $input['amount_minor']
            : PaymentMoney::toMinor($input['amount_major'] ?? 0);

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $method = PaymentMethod::query()->active()->where('code', $methodCode)->first();
        if (! $method) {
            throw new InvalidArgumentException("Payment method [{$methodCode}] is not available.");
        }

        $gatewayDriver = $this->manager->forMethodCode($methodCode);
        $gatewayModel = $this->manager->resolveGatewayModel($gatewayDriver->code());

        return DB::transaction(function () use ($payer, $payable, $method, $gatewayDriver, $gatewayModel, $amountMinor, $currency, $idempotencyKey, $input) {
            if ($idempotencyKey) {
                $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing->load(['method', 'gateway', 'attempts']);
                }
            }

            $payment = Payment::create([
                'payment_reference' => $this->references->next(),
                'payer_id' => $payer->id,
                'booking_id' => $payable instanceof Booking ? $payable->id : null,
                'payable_type' => $payable->getMorphClass(),
                'payable_id' => $payable->getKey(),
                'transaction_type' => 'charge',
                'amount' => PaymentMoney::toMajor($amountMinor),
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'payment_method' => $method->code,
                'payment_method_id' => $method->id,
                'gateway_id' => $gatewayModel?->id,
                'idempotency_key' => $idempotencyKey,
                'status' => PaymentStatuses::INITIATED,
                'initiated_at' => now(),
                'metadata' => array_merge($input['metadata'] ?? [], [
                    'module' => $input['module'] ?? $this->guessModule($payable),
                ]),
            ]);

            $attempt = PaymentAttempt::create([
                'payment_id' => $payment->id,
                'attempt_number' => 1,
                'payment_method_id' => $method->id,
                'gateway_id' => $gatewayModel?->id,
                'status' => PaymentStatuses::INITIATED,
                'phone' => $input['phone'] ?? null,
                'request_payload' => [
                    'method' => $method->code,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                ],
            ]);

            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'payment_attempt_id' => $attempt->id,
                'transaction_type' => 'INITIATED',
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'status' => PaymentStatuses::INITIATED,
            ]);

            $result = $gatewayDriver->initializePayment($payment, $attempt, [
                'phone' => $input['phone'] ?? null,
                'return_url' => $input['return_url'] ?? null,
            ]);

            $attempt->update([
                'status' => $result->status,
                'gateway_reference' => $result->gatewayReference,
                'payment_url' => $result->paymentUrl,
                'response_payload' => $result->raw,
                'failure_reason' => $result->failureReason,
            ]);

            $payment->update([
                'status' => $result->success
                    ? ($result->status === PaymentStatuses::SUCCESS ? PaymentStatuses::SUCCESS : PaymentStatuses::PENDING)
                    : PaymentStatuses::FAILED,
                'gateway_reference' => $result->gatewayReference,
                'transaction_reference' => $result->gatewayReference,
                'payment_url' => $result->paymentUrl,
                'processing_at' => now(),
                'failed_at' => $result->success ? null : now(),
                'failure_reason' => $result->failureReason,
            ]);

            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'payment_attempt_id' => $attempt->id,
                'transaction_type' => 'SUBMITTED',
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'gateway_reference' => $result->gatewayReference,
                'status' => $result->status,
                'response_payload' => $result->raw,
            ]);

            $payment = $payment->fresh(['method', 'gateway', 'attempts']);

            if ($result->success && $result->status === PaymentStatuses::SUCCESS) {
                $payment = $this->verification->verifyAndFinalize($payment, $attempt);
            }

            $this->audit->log('payment.initiated', $payment, null, [
                'payment_reference' => $payment->payment_reference,
                'method' => $method->code,
                'amount_minor' => $amountMinor,
            ]);

            $payment = $payment->fresh(['method', 'gateway', 'attempts']);
            if (! $payment->isSuccessful()) {
                $this->paymentNotifications->notify($payment, 'initiated');
            }

            return $payment;
        });
    }

    /**
     * Retry payment for same payable — new attempt (or new payment if previous terminal failed).
     */
    public function retry(Payment $payment, string $methodCode, ?string $phone = null, ?string $idempotencyKey = null): Payment
    {
        if ($payment->isSuccessful()) {
            return $payment;
        }

        if ($idempotencyKey) {
            $existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['method', 'gateway', 'attempts']);
            }
        }

        $payable = $payment->payable;
        if (! $payable) {
            throw new InvalidArgumentException('Cannot retry payment without payable.');
        }

        // Prefer new payment record to preserve attempt history across methods (spec §21).
        return $this->createAndInitialize([
            'payer' => $payment->payer ?? User::findOrFail($payment->payer_id),
            'payable' => $payable,
            'amount_minor' => (int) $payment->amount_minor,
            'currency' => $payment->currency,
            'method_code' => $methodCode,
            'phone' => $phone,
            'idempotency_key' => $idempotencyKey,
            'metadata' => array_merge($payment->metadata ?? [], ['retried_from' => $payment->payment_reference]),
            'module' => $payment->metadata['module'] ?? null,
        ]);
    }

    public function getStatus(Payment $payment, bool $refreshFromGateway = false): Payment
    {
        if ($refreshFromGateway && ! $payment->isSuccessful()) {
            $attempt = $payment->attempts()->latest('id')->first();

            return $this->verification->verifyAndFinalize($payment, $attempt);
        }

        return $payment->load(['method', 'gateway', 'attempts', 'allocations']);
    }

    protected function guessModule(Model $payable): string
    {
        if ($payable instanceof Booking) {
            return 'transport';
        }
        if ($payable instanceof GarageBooking) {
            return 'garage';
        }
        if ($payable instanceof CargoRequest) {
            return 'cargo';
        }

        return 'marketplace';
    }
}
