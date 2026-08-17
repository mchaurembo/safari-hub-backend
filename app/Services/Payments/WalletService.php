<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function getOrCreate(User $user, string $currency = 'TZS'): WalletAccount
    {
        return WalletAccount::firstOrCreate(
            ['user_id' => $user->id, 'currency' => $currency],
            [
                'available_balance_minor' => 0,
                'pending_balance_minor' => 0,
                'status' => 'active',
            ]
        );
    }

    public function creditFromAllocations(Payment $payment): void
    {
        $payment->loadMissing('allocations');

        foreach ($payment->allocations as $allocation) {
            if ($allocation->allocation_type === 'PLATFORM_COMMISSION') {
                continue;
            }
            if (! $allocation->recipient_id || $allocation->recipient_type !== User::class) {
                continue;
            }
            if ($allocation->status === 'CREDITED') {
                continue;
            }

            $this->credit(
                userId: (int) $allocation->recipient_id,
                amountMinor: (int) $allocation->net_amount_minor,
                currency: $allocation->currency,
                type: 'SERVICE_EARNING',
                reference: $payment->payment_reference,
                source: $allocation,
                pending: false,
            );

            $allocation->update(['status' => 'CREDITED']);
        }
    }

    public function credit(
        int $userId,
        int $amountMinor,
        string $currency,
        string $type,
        ?string $reference,
        ?Model $source = null,
        bool $pending = false,
    ): WalletTransaction {
        return DB::transaction(function () use ($userId, $amountMinor, $currency, $type, $reference, $source, $pending) {
            $user = User::query()->findOrFail($userId);
            $wallet = WalletAccount::query()
                ->where('user_id', $user->id)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = $this->getOrCreate($user, $currency);
                $wallet = WalletAccount::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            }

            if ($pending) {
                $wallet->pending_balance_minor = (int) $wallet->pending_balance_minor + $amountMinor;
                $balanceAfter = (int) $wallet->available_balance_minor;
            } else {
                $wallet->available_balance_minor = (int) $wallet->available_balance_minor + $amountMinor;
                $balanceAfter = (int) $wallet->available_balance_minor;
            }
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'reference' => $reference,
                'credit_minor' => $amountMinor,
                'debit_minor' => 0,
                'balance_after_minor' => $balanceAfter,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]);
        });
    }

    public function debit(
        int $userId,
        int $amountMinor,
        string $currency,
        string $type,
        ?string $reference,
        ?Model $source = null,
    ): WalletTransaction {
        return DB::transaction(function () use ($userId, $amountMinor, $currency, $type, $reference, $source) {
            $wallet = WalletAccount::query()
                ->where('user_id', $userId)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $wallet->available_balance_minor < $amountMinor) {
                throw new \RuntimeException('Insufficient wallet balance.');
            }

            $wallet->available_balance_minor = (int) $wallet->available_balance_minor - $amountMinor;
            $wallet->save();

            return WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => $type,
                'reference' => $reference,
                'credit_minor' => 0,
                'debit_minor' => $amountMinor,
                'balance_after_minor' => (int) $wallet->available_balance_minor,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]);
        });
    }
}
