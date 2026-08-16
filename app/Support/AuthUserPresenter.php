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
        $user->loadMissing(['role', 'roles', 'transportOwner', 'driver', 'garages', 'technicians']);

        $capabilities = $user->capabilitySummaries();
        $permissions = $user->permissionCodes();
        $workspaces = self::workspaces($user, $capabilities);

        $payload = $user->toArray();
        $payload['capabilities'] = $capabilities;
        $payload['permissions'] = $permissions;
        $payload['workspaces'] = $workspaces;

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
        $garageCount = $user->garages->count();
        $techCount = $user->technicians->count();

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
                'available' => $has('technician') && $techCount > 0,
                'reason' => $has('technician') && $techCount === 0 ? 'technician_link_missing' : null,
                'resources' => $techCount > 0 ? ['technician_links' => $techCount] : null,
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
