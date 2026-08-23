<?php

namespace App\Support;

class ChapaCapabilities
{
    /** @return list<string> */
    public static function platform(): array
    {
        return config('chapa.platform_capabilities', ['customer', 'admin']);
    }

    /** @return list<string> */
    public static function businessMapped(): array
    {
        return config('chapa.business_mapped_capabilities', []);
    }

    public static function isPlatform(string $code): bool
    {
        return in_array($code, self::platform(), true);
    }

    public static function isBusinessMapped(string $code): bool
    {
        return in_array($code, self::businessMapped(), true);
    }

    public static function legacyPivotWritesEnabled(): bool
    {
        return (bool) config('chapa.legacy_capability_pivot_writes', false);
    }

    /** @return list<string> */
    public static function selfEnrollableRoles(): array
    {
        if (self::legacyPivotWritesEnabled()) {
            return ['customer', 'owner', 'garage_owner', 'technician'];
        }

        return ['customer'];
    }
}
