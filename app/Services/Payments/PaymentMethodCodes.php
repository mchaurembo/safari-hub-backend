<?php

namespace App\Services\Payments;

final class PaymentMethodCodes
{
    public const VISA = 'VISA';

    public const MASTERCARD = 'MASTERCARD';

    public const MPESA = 'MPESA';

    public const MIXX_BY_YAS = 'MIXX_BY_YAS';

    public const AIRTEL_MONEY = 'AIRTEL_MONEY';

    public const HALOPESA = 'HALOPESA';

    public const BANK_TRANSFER = 'BANK_TRANSFER';

    public static function all(): array
    {
        return [
            self::VISA,
            self::MASTERCARD,
            self::MPESA,
            self::MIXX_BY_YAS,
            self::AIRTEL_MONEY,
            self::HALOPESA,
            self::BANK_TRANSFER,
        ];
    }
}
