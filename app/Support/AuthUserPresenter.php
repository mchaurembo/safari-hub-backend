<?php

namespace App\Support;

use App\Models\User;

class AuthUserPresenter
{
    /**
     * Enrich a user model for auth responses (login, /me, enroll).
     * Keeps legacy `role` / `roles` while adding capabilities, permissions, workspaces.
     */
    public static function present(User $user): array
    {
        $user->refreshLegacyPrimaryRole();
        $user->loadMissing(['role', 'roles', 'transportOwner', 'driver', 'garages', 'technicians', 'garageMemberships', 'employmentRelationships']);

        $capabilities = $user->capabilitySummaries();
        $permissions = $user->permissionCodes();
        $workspaces = self::workspaces($user, $capabilities);

        $payload = $user->toArray();
        $payload['capabilities'] = $capabilities;
        $payload['permissions'] = $permissions;
        $payload['workspaces'] = $workspaces;

        $managedFleet = $user->managedTransportFleet();
        if ($managedFleet) {
            $payload['managed_transport_owner'] = $managedFleet->toArray();
        }

        // Legacy clients still read user.role — derive from preferred capability if missing.
        if (empty($payload['role'])) {
            $primary = $user->preferredPrimaryRole();
            if ($primary) {
                $payload['role'] = $primary->toArray();
            }
        }

        return $payload;
    }

    /**
     * @param  list<array{code: string, status: string}>  $capabilities
     * @return list<array{id: string, available: bool, reason?: string, resources?: array}>
     */
    private static function workspaces(User $user, array $capabilities): array
    {
        $active = collect($capabilities)
            ->filter(fn ($c) => ($c['status'] ?? null) === 'active')
            ->pluck('code')
            ->all();

        $has = fn (string $code) => in_array($code, $active, true);

        $fleet = $user->transportOwner;
        $managedFleet = $user->managedTransportFleet();
        $garageCount = $user->garages->count();
        $activeTechnician = $user->hasActiveTechnicianWorkspace();
        $managerGarageMembership = $user->activeGarageManagerMembership();

        $list = [
            [
                'id' => 'hub',
                'available' => true,
            ],
            [
                'id' => 'customer',
                'available' => $has('customer') || $has('admin'),
            ],
            [
                'id' => 'owner',
                'available' => $has('owner') && (bool) $fleet,
                'reason' => $has('owner') && ! $fleet ? 'fleet_profile_missing' : null,
                'resources' => $fleet ? ['fleet_id' => $fleet->id, 'fleet_status' => $fleet->status] : null,
            ],
            [
                'id' => 'driver',
                // Driver workspace only after an owner has hired this person.
                'available' => $has('driver') && $user->driver && $user->driver->owner_id,
                'reason' => $has('driver') && (! $user->driver || ! $user->driver->owner_id)
                    ? 'awaiting_employment'
                    : null,
                'resources' => ($user->driver && $user->driver->owner_id) ? [
                    'driver_id' => $user->driver->id,
                    'fleet_id' => $user->driver->owner_id,
                ] : null,
            ],
            [
                'id' => 'garage_owner',
                'available' => $has('garage_owner') && $garageCount > 0,
                'reason' => $has('garage_owner') && $garageCount === 0 ? 'garage_missing' : null,
                'resources' => $garageCount > 0 ? ['garage_count' => $garageCount] : null,
            ],
            [
                'id' => 'technician',
                'available' => $has('technician') && $activeTechnician,
                'reason' => $has('technician') && ! $activeTechnician
                    ? ($user->technicians()->exists() ? 'technician_deactivated' : 'technician_link_missing')
                    : null,
                'resources' => $activeTechnician ? [
                    'technician_links' => $user->technicians()->whereIn('status', ['active', 'busy'])->count(),
                ] : null,
            ],
            [
                'id' => 'transport_manager',
                'available' => $has('transport_manager') && (bool) $managedFleet,
                'reason' => $has('transport_manager') && ! $managedFleet ? 'awaiting_fleet_assignment' : null,
                'resources' => $managedFleet ? [
                    'fleet_id' => $managedFleet->id,
                    'fleet_status' => $managedFleet->status,
                ] : null,
            ],
            [
                'id' => 'garage_manager',
                'available' => $has('garage_manager') && (bool) $managerGarageMembership,
                'reason' => $has('garage_manager') && ! $managerGarageMembership ? 'awaiting_garage_assignment' : null,
                'resources' => $managerGarageMembership ? [
                    'garage_id' => $managerGarageMembership->garage_id,
                ] : null,
            ],
            [
                'id' => 'admin',
                'available' => $has('admin'),
            ],
        ];

        return array_map(function (array $ws) {
            if (! array_key_exists('reason', $ws) || $ws['reason'] === null) {
                unset($ws['reason']);
            }
            if (! array_key_exists('resources', $ws) || $ws['resources'] === null) {
                unset($ws['resources']);
            }

            return $ws;
        }, $list);
    }
}
