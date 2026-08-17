<?php

namespace App\Services\Payments;

final class PaymentStatuses
{
    public const INITIATED = 'INITIATED';

    public const PENDING = 'PENDING';

    public const PROCESSING = 'PROCESSING';

    public const SUCCESS = 'SUCCESS';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const EXPIRED = 'EXPIRED';

    public const REFUNDED = 'REFUNDED';

    public const PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    /** Legacy statuses still accepted in revenue queries. */
    public const LEGACY_COMPLETED = 'completed';

    public const LEGACY_PENDING = 'pending';

    public static function successStates(): array
    {
        return [self::SUCCESS, self::LEGACY_COMPLETED];
    }

    public static function isTerminalSuccess(string $status): bool
    {
        return in_array($status, self::successStates(), true);
    }

    public static function isTerminalFailure(string $status): bool
    {
        return in_array($status, [self::FAILED, self::CANCELLED, self::EXPIRED], true);
    }
}
