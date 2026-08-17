<?php

namespace App\Services\Payments\DTOs;

final class GatewayStatusResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $gatewayReference = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?array $raw = null,
        public readonly ?string $failureReason = null,
    ) {}
}
