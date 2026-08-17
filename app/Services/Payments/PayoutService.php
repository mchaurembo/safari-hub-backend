<?php

namespace App\Services\Payments;

use App\Models\Payout;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayoutService
{
    public function __construct(
        private PaymentManager $manager,
        private PaymentReferenceGenerator $references,
        private WalletService $wallets,
        private AuditLogger $audit,
    ) {}

    public function request(User $recipient, int $amountMinor, string $currency = 'TZS', ?string $method = null): Payout
    {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Payout amount must be positive.');
        }

        $wallet = $this->wallets->getOrCreate($recipient, $currency);
        if ((int) $wallet->available_balance_minor < $amountMinor) {
            throw new InvalidArgumentException('Insufficient available balance.');
        }

        return Payout::create([
            'payout_reference' => $this->references->nextPayout(),
            'recipient_id' => $recipient->id,
            'wallet_id' => $wallet->id,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payout_method' => $method,
            'gateway_id' => $this->manager->resolveGatewayModel()?->id,
            'status' => 'PENDING',
            'requested_at' => now(),
        ]);
    }

    public function process(Payout $payout): Payout
    {
        return DB::transaction(function () use ($payout) {
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status === PaymentStatuses::SUCCESS) {
                return $payout;
            }

            $payout->update(['status' => 'PROCESSING']);

            $this->wallets->debit(
                (int) $payout->recipient_id,
                (int) $payout->amount_minor,
                $payout->currency,
                'PAYOUT',
                $payout->payout_reference,
                $payout,
            );

            $gateway = $this->manager->forGateway($payout->gateway);
            $result = $gateway->createPayout($payout);

            if ($result->success) {
                $payout->update([
                    'status' => PaymentStatuses::SUCCESS,
                    'gateway_reference' => $result->gatewayReference,
                    'processed_at' => now(),
                    'metadata' => ['gateway' => $result->raw],
                ]);
                $this->audit->log('payment.payout_success', $payout);
            } else {
                // Re-credit on failure
                $this->wallets->credit(
                    (int) $payout->recipient_id,
                    (int) $payout->amount_minor,
                    $payout->currency,
                    'REVERSAL',
                    $payout->payout_reference,
                    $payout,
                );
                $payout->update([
                    'status' => PaymentStatuses::FAILED,
                    'failed_at' => now(),
                    'failure_reason' => $result->failureReason,
                ]);
            }

            return $payout->fresh();
        });
    }
}
