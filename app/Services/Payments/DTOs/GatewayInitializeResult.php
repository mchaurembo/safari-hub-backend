<?php

namespace App\Services\Payments\DTOs;

final class GatewayInitializeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $paymentUrl = null,
        public readonly ?array $raw = null,
        public readonly ?string $failureReason = null,
    ) {}
}
