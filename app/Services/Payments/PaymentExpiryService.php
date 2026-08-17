<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;

class PaymentExpiryService
{
    /** @var array<int, string> */
    private const OPEN_STATUSES = [
        PaymentStatuses::INITIATED,
        PaymentStatuses::PENDING,
        PaymentStatuses::PROCESSING,
    ];

    /**
     * Mark abandoned checkouts as EXPIRED.
     *
     * @return int Number of payments expired
     */
    public function expireStale(?int $hours = null): int
    {
        $hours = $hours ?? (int) config('payments.pending_expiry_hours', 24);
        $cutoff = now()->subHours(max(0, $hours));

        $query = Payment::query()->whereIn('status', self::OPEN_STATUSES);
        if ($hours > 0) {
            $query->where('created_at', '<=', $cutoff);
        }

        $ids = $query->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $count = 0;
        $notifications = app(PaymentNotificationService::class);

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count, $notifications) {
                $payment = Payment::query()->whereKey($id)->lockForUpdate()->first();
                if (! $payment || ! in_array($payment->status, self::OPEN_STATUSES, true)) {
                    return;
                }

                $reason = 'Checkout not completed within the allowed time.';

                $payment->update([
                    'status' => PaymentStatuses::EXPIRED,
                    'expired_at' => now(),
                    'failure_reason' => $reason,
                ]);

                PaymentAttempt::query()
                    ->where('payment_id', $payment->id)
                    ->whereIn('status', self::OPEN_STATUSES)
                    ->update([
                        'status' => PaymentStatuses::EXPIRED,
                        'failure_reason' => $reason,
                    ]);

                $notifications->notify($payment->fresh(), 'expired');
                $count++;
            });
        }

        return $count;
    }

    public function countStale(?int $hours = null): int
    {
        $hours = $hours ?? (int) config('payments.pending_expiry_hours', 24);
        $cutoff = now()->subHours(max(0, $hours));

        return Payment::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('created_at', '<=', $cutoff)
            ->count();
    }
}
