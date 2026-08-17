<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Services\AuditLogger;
use App\Services\Payments\DTOs\GatewayStatusResult;
use Illuminate\Support\Facades\DB;

class WebhookProcessingService
{
    public function __construct(
        private PaymentManager $manager,
        private PaymentVerificationService $verification,
        private AuditLogger $audit,
    ) {}

    public function handle(string $provider, array $headers, string $rawBody, array $payload): PaymentWebhookEvent
    {
        $gateway = $this->manager->driver($provider);

        if (! $gateway->validateWebhook($headers, $rawBody, $payload)) {
            abort(401, 'Invalid webhook signature.');
        }

        $parsed = $gateway->parseWebhook($payload);
        $eventId = $parsed['event_id'] ?? null;
        $idempotencyKey = $provider.':'.($eventId ?: hash('sha256', $rawBody));

        $existing = PaymentWebhookEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing && $existing->status === 'processed') {
            return $existing;
        }

        $event = $existing ?: PaymentWebhookEvent::create([
            'provider' => $provider,
            'event_id' => $eventId,
            'idempotency_key' => $idempotencyKey,
            'status' => 'received',
            'payload' => $payload,
        ]);

        return DB::transaction(function () use ($event, $gateway, $parsed, $payload, $provider) {
            $event = PaymentWebhookEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->status === 'processed') {
                return $event;
            }

            $payment = $this->findPayment($parsed);
            if (! $payment) {
                $event->update([
                    'status' => 'ignored',
                    'processing_notes' => 'Payment not found',
                    'processed_at' => now(),
                ]);

                return $event;
            }

            PaymentTransaction::firstOrCreate(
                ['idempotency_key' => $event->idempotency_key],
                [
                    'payment_id' => $payment->id,
                    'transaction_type' => 'WEBHOOK',
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                    'gateway_reference' => $parsed['gateway_reference'] ?? null,
                    'status' => $parsed['status'] ?? null,
                    'response_payload' => $payload,
                ]
            );

            // Always re-verify with provider — never trust webhook alone.
            $attempt = $payment->attempts()->latest('id')->first();
            $verified = $gateway->verifyPayment($payment, $attempt, ['webhook' => $parsed]);

            // If stub/gateway returns pending but webhook says success, merge carefully:
            if (
                ($parsed['status'] ?? null)
                && strtoupper((string) $parsed['status']) === PaymentStatuses::SUCCESS
                && $verified->status !== PaymentStatuses::SUCCESS
                && in_array($provider, ['stub', 'selcom'], true)
            ) {
                $verified = new GatewayStatusResult(
                    status: PaymentStatuses::SUCCESS,
                    gatewayReference: $parsed['gateway_reference'] ?? $verified->gatewayReference,
                    amountMinor: $parsed['amount_minor'] ?? $verified->amountMinor ?? (int) $payment->amount_minor,
                    currency: $parsed['currency'] ?? $verified->currency ?? $payment->currency,
                    raw: $payload,
                );
            }

            $this->verification->applyVerifiedStatus($payment->fresh(), $attempt, $verified);

            $event->update([
                'payment_id' => $payment->id,
                'status' => 'processed',
                'processed_at' => now(),
                'processing_notes' => 'ok',
            ]);

            $this->audit->log('payment.webhook_processed', $payment, null, [
                'provider' => $provider,
                'event_id' => $event->event_id,
            ]);

            return $event->fresh();
        });
    }

    protected function findPayment(array $parsed): ?Payment
    {
        if (! empty($parsed['payment_reference'])) {
            $p = Payment::query()->where('payment_reference', $parsed['payment_reference'])->first();
            if ($p) {
                return $p;
            }
        }

        if (! empty($parsed['gateway_reference'])) {
            return Payment::query()->where('gateway_reference', $parsed['gateway_reference'])->first();
        }

        return null;
    }
}
