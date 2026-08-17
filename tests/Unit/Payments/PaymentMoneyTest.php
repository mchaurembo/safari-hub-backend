<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\PaymentMoney;
use PHPUnit\Framework\TestCase;

class PaymentMoneyTest extends TestCase
{
    public function test_to_minor_and_major_round_trip(): void
    {
        $this->assertSame(15000000, PaymentMoney::toMinor('150000'));
        $this->assertSame('150000.00', PaymentMoney::toMajor(15000000));
    }

    public function test_percent_of_uses_integer_math(): void
    {
        // 10% of 150000.00 major (15_000_000 minor) = 15_000.00 major
        $this->assertSame(1500000, PaymentMoney::percentOf(15000000, '10'));
    }
}
