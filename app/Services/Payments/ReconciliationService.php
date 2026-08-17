<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Payout;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function summary(?string $from = null, ?string $to = null): array
    {
        $payments = Payment::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $successful = (clone $payments)->successful();

        $refunds = Refund::query()
            ->where('status', PaymentStatuses::SUCCESS)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $payouts = Payout::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        $commission = PaymentAllocation::query()
            ->where('allocation_type', 'PLATFORM_COMMISSION')
            ->whereIn('payment_id', (clone $successful)->select('id'));

        $provider = PaymentAllocation::query()
            ->where('allocation_type', '!=', 'PLATFORM_COMMISSION')
            ->whereIn('payment_id', (clone $successful)->select('id'));

        return [
            'total_payments' => (clone $payments)->count(),
            'successful_payments' => (clone $successful)->count(),
            'pending_payments' => (clone $payments)->whereIn('status', [
                PaymentStatuses::INITIATED, PaymentStatuses::PENDING, PaymentStatuses::PROCESSING,
            ])->count(),
            'failed_payments' => (clone $payments)->whereIn('status', [
                PaymentStatuses::FAILED, PaymentStatuses::CANCELLED, PaymentStatuses::EXPIRED,
            ])->count(),
            'expired_payments' => (clone $payments)->where('status', PaymentStatuses::EXPIRED)->count(),
            'total_revenue_minor' => (int) (clone $successful)->sum('amount_minor'),
            'refunds_count' => (clone $refunds)->count(),
            'refunds_minor' => (int) (clone $refunds)->sum('amount_minor'),
            'platform_commission_minor' => (int) $commission->sum('net_amount_minor'),
            'provider_earnings_minor' => (int) $provider->sum('net_amount_minor'),
            'pending_payouts' => (clone $payouts)->where('status', 'PENDING')->count(),
            'pending_payouts_minor' => (int) (clone $payouts)->where('status', 'PENDING')->sum('amount_minor'),
            'successful_payouts_minor' => (int) (clone $payouts)->where('status', PaymentStatuses::SUCCESS)->sum('amount_minor'),
            'by_method' => Payment::query()
                ->successful()
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount_minor) as total_minor'))
                ->groupBy('payment_method')
                ->get(),
            'by_day' => Payment::query()
                ->successful()
                ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
                ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as count'), DB::raw('SUM(amount_minor) as total_minor'))
                ->groupBy('day')
                ->orderBy('day')
                ->get(),
        ];
    }
}
