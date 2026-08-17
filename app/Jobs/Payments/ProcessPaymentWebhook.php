<?php

namespace App\Jobs\Payments;

use App\Models\PaymentWebhookEvent;
use App\Services\Payments\WebhookProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $provider,
        public array $headers,
        public string $rawBody,
        public array $payload,
    ) {}

    public function handle(WebhookProcessingService $webhooks): PaymentWebhookEvent
    {
        return $webhooks->handle($this->provider, $this->headers, $this->rawBody, $this->payload);
    }
}
