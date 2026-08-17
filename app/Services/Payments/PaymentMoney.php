<?php

namespace App\Services\Payments;

/**
 * Integer minor-unit money helpers. Never use floats for financial math.
 * Convention: amount_minor = major units × 100 (e.g. TZS 100,000 → 10_000_000).
 */
final class PaymentMoney
{
    public static function toMinor(string|int|float $major, int $scale = 2): int
    {
        $normalized = is_string($major) ? $major : number_format((float) $major, $scale, '.', '');
        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new \InvalidArgumentException('Invalid monetary amount.');
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $minor = ((int) $whole) * (10 ** $scale) + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    public static function toMajor(int $minor, int $scale = 2): string
    {
        $negative = $minor < 0;
        $minor = abs($minor);
        $factor = 10 ** $scale;
        $whole = intdiv($minor, $factor);
        $fraction = str_pad((string) ($minor % $factor), $scale, '0', STR_PAD_LEFT);
        $value = $scale > 0 ? "{$whole}.{$fraction}" : (string) $whole;

        return $negative ? "-{$value}" : $value;
    }

    public static function percentOf(int $amountMinor, string $percent): int
    {
        // percent e.g. "10.5" → amount * 10.5 / 100
        $basisPoints = self::toMinor($percent, 4); // 10.5% → 105000 (4dp of percent)

        return intdiv($amountMinor * $basisPoints, 1_000_000);
    }
}
