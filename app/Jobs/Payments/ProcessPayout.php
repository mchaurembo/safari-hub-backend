<?php

namespace App\Jobs\Payments;

use App\Models\Payout;
use App\Services\Payments\PayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $payoutId) {}

    public function handle(PayoutService $payouts): void
    {
        $payout = Payout::query()->find($this->payoutId);
        if ($payout) {
            $payouts->process($payout);
        }
    }
}
