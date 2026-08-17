<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Payments\PaymentExpiryService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:expire-stale {--hours= : Override expiry window in hours}', function () {
    $hours = $this->option('hours');
    $hours = $hours !== null && $hours !== '' ? (int) $hours : null;

    $count = app(PaymentExpiryService::class)->expireStale($hours);
    $this->info("Expired {$count} payment(s).");
})->purpose('Mark abandoned pending payments as EXPIRED');
