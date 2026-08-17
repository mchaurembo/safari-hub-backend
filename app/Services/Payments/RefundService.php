<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RefundService
{
    public function __construct(
        private PaymentManager $manager,
        private PaymentReferenceGenerator $references,
        private WalletService $wallets,
        private AuditLogger $audit,
    ) {}

    public function request(Payment $payment, int $amountMinor, User $requester, ?string $reason = null): Refund
    {
        if (! $payment->isSuccessful() && $payment->status !== PaymentStatuses::PARTIALLY_REFUNDED) {
            throw new InvalidArgumentException('Only successful payments can be refunded.');
        }

        $already = (int) $payment->refunds()->whereIn('status', [PaymentStatuses::SUCCESS, 'PENDING', 'PROCESSING'])->sum('amount_minor');
        $remaining = (int) $payment->amount_minor - $already;

        if ($amountMinor <= 0 || $amountMinor > $remaining) {
            throw new InvalidArgumentException('Invalid refund amount.');
        }

        return Refund::create([
            'refund_reference' => $this->references->nextRefund(),
            'payment_id' => $payment->id,
            'amount_minor' => $amountMinor,
            'currency' => $payment->currency,
            'reason' => $reason,
            'status' => 'PENDING',
            'requested_by' => $requester->id,
        ]);
    }

    public function process(Refund $refund, ?User $approver = null): Refund
    {
        return DB::transaction(function () use ($refund, $approver) {
            $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($refund->status === PaymentStatuses::SUCCESS) {
                return $refund;
            }

            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $gateway = $this->manager->forGateway($payment->gateway);
            $result = $gateway->refundPayment($refund, $payment);

            $success = in_array(strtoupper($result->status), [PaymentStatuses::SUCCESS, 'COMPLETED'], true);

            $refund->update([
                'status' => $success ? PaymentStatuses::SUCCESS : PaymentStatuses::FAILED,
                'gateway_reference' => $result->gatewayReference,
                'approved_by' => $approver?->id,
                'processed_at' => $success ? now() : null,
                'metadata' => ['gateway' => $result->raw],
            ]);

            if ($success) {
                $refundedTotal = (int) $payment->refunds()->where('status', PaymentStatuses::SUCCESS)->sum('amount_minor');
                $payment->update([
                    'status' => $refundedTotal >= (int) $payment->amount_minor
                        ? PaymentStatuses::REFUNDED
                        : PaymentStatuses::PARTIALLY_REFUNDED,
                ]);

                // Reverse provider wallet when possible (platform commission not auto-reversed here).
                foreach ($payment->allocations()->where('allocation_type', '!=', 'PLATFORM_COMMISSION')->get() as $allocation) {
                    if ($allocation->recipient_type === User::class && $allocation->recipient_id) {
                        $share = (int) floor(((int) $allocation->net_amount_minor) * $refund->amount_minor / max(1, (int) $payment->amount_minor));
                        if ($share > 0) {
                            try {
                                $this->wallets->debit(
                                    (int) $allocation->recipient_id,
                                    $share,
                                    $payment->currency,
                                    'REFUND',
                                    $refund->refund_reference,
                                    $refund,
                                );
                            } catch (\Throwable) {
                                // Leave for reconciliation if balance insufficient.
                            }
                        }
                    }
                }

                $this->audit->log('payment.refund_success', $refund, null, [
                    'payment_reference' => $payment->payment_reference,
                    'amount_minor' => $refund->amount_minor,
                ]);
            }

            return $refund->fresh();
        });
    }
}
