<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentReferenceGenerator
{
    public function next(?string $prefix = null): string
    {
        $prefix = $prefix ?: (string) config('payments.reference_prefix', 'SH-PAY');
        $date = now()->format('Ymd');

        return DB::transaction(function () use ($prefix, $date) {
            $like = "{$prefix}-{$date}-%";
            $latest = Payment::query()
                ->where('payment_reference', 'like', $like)
                ->lockForUpdate()
                ->orderByDesc('payment_reference')
                ->value('payment_reference');

            $seq = 1;
            if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
                $seq = ((int) $m[1]) + 1;
            }

            return sprintf('%s-%s-%06d', $prefix, $date, $seq);
        });
    }

    public function nextRefund(): string
    {
        return 'SH-REF-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function nextPayout(): string
    {
        return 'SH-PO-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
    }
}
