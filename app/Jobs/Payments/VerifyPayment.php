<?php

namespace App\Jobs\Payments;

use App\Models\Payment;
use App\Services\Payments\PaymentVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $paymentId) {}

    public function handle(PaymentVerificationService $verification): void
    {
        $payment = Payment::query()->find($this->paymentId);
        if (! $payment) {
            return;
        }

        $attempt = $payment->attempts()->latest('id')->first();
        $verification->verifyAndFinalize($payment, $attempt);
    }
}
