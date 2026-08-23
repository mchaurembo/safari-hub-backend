<?php

namespace App\Services;

use App\Models\BusinessMembership;
use App\Models\Garage;
use App\Models\TransportOwner;
use App\Models\User;

/**
 * Derives legacy capability codes and permissions from CHAPA business memberships.
 * Phase 5c: business membership becomes the source of truth; user_roles stay synced for compat.
 */
class BusinessCapabilityBridgeService
{
    public function __construct(
        private readonly BusinessAuthorizationService $authorization,
        private readonly LegacyBusinessAccessService $legacyAccess,
    ) {}

    /** @return list<string> */
    public function derivedCapabilityCodes(User $user): array
    {
        $user->loadMissing(['activeBusinessMemberships.role', 'activeBusinessMemberships.position', 'activeBusinessMemberships.business.type']);

        return $user->activeBusinessMemberships
            ->flatMap(fn (BusinessMembership $m) => $this->legacyCodesForMembership($m))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function derivedPermissionCodes(User $user): array
    {
        $user->loadMissing(['activeBusinessMemberships.role', 'activeBusinessMemberships.position', 'activeBusinessMemberships.business']);

        return $user->activeBusinessMemberships
            ->flatMap(fn (BusinessMembership $m) => $this->authorization->effectivePermissions($m))
            ->unique()
            ->values()
            ->all();
    }

    public function hasDerivedCapability(User $user, string $code): bool
    {
        return in_array($code, $this->derivedCapabilityCodes($user), true);
    }

    public function hasDerivedPermission(User $user, string $code): bool
    {
        return in_array($code, $this->derivedPermissionCodes($user), true);
    }

    /**
     * Keep legacy user_roles aligned with active business memberships (transition helper).
     */
    public function syncLegacyCapabilities(User $user): void
    {
        if (! \App\Support\ChapaCapabilities::legacyPivotWritesEnabled()) {
            return;
        }

        foreach ($this->derivedCapabilityCodes($user) as $code) {
            $user->enrollCapability($code);
        }
    }

    /** @return list<string> */
    public function legacyCodesForMembership(BusinessMembership $membership): array
    {
        $membership->loadMissing(['role', 'position', 'business.type']);

        return $this->legacyAccess->legacyCapabilityCodesForMembership(
            $membership->user_id,
            $membership->business_id,
        );
    }

    /**
     * Workspace hints derived from business memberships (merged into /me workspaces).
     *
     * @return list<array<string, mixed>>
     */
    public function businessWorkspaces(User $user): array
    {
        $user->loadMissing([
            'activeBusinessMemberships.business',
            'activeBusinessMemberships.role',
            'activeBusinessMemberships.position',
            'transportOwner',
            'garages',
            'driver',
            'technicians',
        ]);

        $entries = [];

        foreach ($user->activeBusinessMemberships as $membership) {
            $biz = $membership->business;
            if (! $biz) {
                continue;
            }

            foreach ($this->legacyCodesForMembership($membership) as $code) {
                $entries[] = $this->workspaceEntryForCode($user, $code, $biz->id, $biz->legacy_transport_owner_id, $biz->legacy_garage_id);
            }
        }

        return $this->dedupeWorkspaces($entries);
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function dedupeWorkspaces(array $entries): array
    {
        $byId = [];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            if (! isset($byId[$id])) {
                $byId[$id] = $entry;
                continue;
            }
            if ($entry['available'] && ! $byId[$id]['available']) {
                $byId[$id] = $entry;
            }
        }

        return array_values($byId);
    }

    private function workspaceEntryForCode(
        User $user,
        string $code,
        int $businessId,
        ?int $legacyFleetId,
        ?int $legacyGarageId,
    ): array {
        $base = [
            'source' => 'business_membership',
            'business_id' => $businessId,
        ];

        return match ($code) {
            'owner' => array_merge($base, [
                'id' => 'owner',
                'available' => (bool) ($legacyFleetId ? TransportOwner::find($legacyFleetId) : $user->transportOwner),
                'reason' => ! $legacyFleetId && ! $user->transportOwner ? 'fleet_profile_missing' : null,
                'resources' => $legacyFleetId ? ['fleet_id' => $legacyFleetId, 'business_id' => $businessId] : null,
            ]),
            'transport_manager' => array_merge($base, [
                'id' => 'transport_manager',
                'available' => (bool) $legacyFleetId,
                'reason' => ! $legacyFleetId ? 'awaiting_fleet_assignment' : null,
                'resources' => $legacyFleetId ? ['fleet_id' => $legacyFleetId, 'business_id' => $businessId] : null,
            ]),
            'driver' => array_merge($base, [
                'id' => 'driver',
                'available' => (bool) ($user->driver && $user->driver->owner_id),
                'reason' => (! $user->driver || ! $user->driver->owner_id) ? 'awaiting_employment' : null,
                'resources' => ($user->driver && $user->driver->owner_id) ? [
                    'driver_id' => $user->driver->id,
                    'fleet_id' => $user->driver->owner_id,
                    'business_id' => $businessId,
                ] : null,
            ]),
            'garage_owner' => array_merge($base, [
                'id' => 'garage_owner',
                'available' => (bool) ($legacyGarageId ? Garage::find($legacyGarageId) : $user->garages->isNotEmpty()),
                'reason' => ! $legacyGarageId && $user->garages->isEmpty() ? 'garage_missing' : null,
                'resources' => $legacyGarageId
                    ? ['garage_id' => $legacyGarageId, 'business_id' => $businessId]
                    : ($user->garages->isNotEmpty() ? ['garage_count' => $user->garages->count(), 'business_id' => $businessId] : null),
            ]),
            'garage_manager' => array_merge($base, [
                'id' => 'garage_manager',
                'available' => (bool) $legacyGarageId,
                'reason' => ! $legacyGarageId ? 'awaiting_garage_assignment' : null,
                'resources' => $legacyGarageId ? ['garage_id' => $legacyGarageId, 'business_id' => $businessId] : null,
            ]),
            'technician' => array_merge($base, [
                'id' => 'technician',
                'available' => $user->hasActiveTechnicianWorkspace(),
                'reason' => ! $user->hasActiveTechnicianWorkspace()
                    ? ($user->technicians()->exists() ? 'technician_deactivated' : 'technician_link_missing')
                    : null,
                'resources' => $user->hasActiveTechnicianWorkspace()
                    ? ['business_id' => $businessId, 'technician_links' => $user->technicians()->whereIn('status', ['active', 'busy'])->count()]
                    : null,
            ]),
            default => array_merge($base, ['id' => $code, 'available' => true]),
        };
    }
}
