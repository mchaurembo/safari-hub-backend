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

    /** Roles a user may add themselves (without an employer invite). */
    public static function selfEnrollableRoles(): array
    {
        if (self::legacyPivotWritesEnabled()) {
            return ['customer', 'owner', 'garage_owner', 'technician'];
        }

        // Business owner / garage / technician come from memberships — register a business instead.
        return ['customer'];
    }

    /**
     * Roles a user may leave themselves.
     * Customer is permanent (default workspace). Technician/managers/drivers are owner-managed.
     *
     * @return list<string>
     */
    public static function selfUnenrollableRoles(): array
    {
        return ['owner', 'garage_owner'];
    }
}
