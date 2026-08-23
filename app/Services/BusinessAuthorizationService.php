<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Support\Facades\Cache;

class BusinessAuthorizationService
{
    /** @return list<string> */
    public function effectivePermissions(BusinessMembership $membership): array
    {
        $membership->loadMissing(['role.permissions', 'position.permissions', 'business.capabilityAssignments.capability']);

        $codes = $membership->role?->permissions->pluck('code') ?? collect();

        if ($membership->position) {
            $codes = $codes->merge($membership->position->permissions->pluck('code'));
        }

        $enabledCapabilities = $membership->business->capabilityAssignments
            ->where('enabled', true)
            ->pluck('capability.code')
            ->filter()
            ->values()
            ->all();

        return $codes
            ->unique()
            ->filter(fn (string $code) => $this->permissionAllowedByCapabilities($code, $enabledCapabilities))
            ->values()
            ->all();
    }

    public function can(BusinessMembership $membership, string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions($membership), true);
    }

    public function resolveMembership(User $user, int $businessId): ?BusinessMembership
    {
        return BusinessMembership::query()
            ->where('user_id', $user->id)
            ->where('business_id', $businessId)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->with(['role', 'position', 'business'])
            ->first();
    }

    public function storeContext(User $user, BusinessContext $context): void
    {
        Cache::put($this->cacheKey($user->id), $context->toArray(), now()->addDays(30));
    }

    public function currentContext(User $user): ?BusinessContext
    {
        $cached = Cache::get($this->cacheKey($user->id));
        if (! is_array($cached) || empty($cached['business_id'])) {
            return null;
        }

        $membership = $this->resolveMembership($user, (int) $cached['business_id']);
        if (! $membership) {
            Cache::forget($this->cacheKey($user->id));

            return null;
        }

        $branch = null;
        if (! empty($cached['branch_id'])) {
            $branch = $membership->business->branches()->find($cached['branch_id']);
        }

        return new BusinessContext(
            business: $membership->business,
            membership: $membership,
            branch: $branch,
            permissions: $this->effectivePermissions($membership),
        );
    }

    public function clearContext(User $user): void
    {
        Cache::forget($this->cacheKey($user->id));
    }

    private function cacheKey(int $userId): string
    {
        return "chapa:business_context:{$userId}";
    }

    /**
     * @param  list<string>  $enabledCapabilities
     */
    private function permissionAllowedByCapabilities(string $permission, array $enabledCapabilities): bool
    {
        $moduleMap = [
            'vehicle.' => 'vehicle_management',
            'driver.' => 'driver_management',
            'trip.' => 'fleet_management',
            'booking.' => 'booking_management',
            'garage.' => 'service_management',
            'garage_booking.' => 'booking_management',
            'technician.' => 'work_order_management',
            'work_order.' => 'work_order_management',
            'product.' => 'product_management',
            'inventory.' => 'inventory_management',
            'order.' => 'order_management',
            'payment.' => 'payment_management',
            'business.' => null,
            'report.' => 'reporting',
            'financial_report.' => 'reporting',
            'admin.' => null,
        ];

        foreach ($moduleMap as $prefix => $capability) {
            if (! str_starts_with($permission, $prefix)) {
                continue;
            }

            if ($capability === null) {
                return true;
            }

            return in_array($capability, $enabledCapabilities, true);
        }

        return true;
    }
}
