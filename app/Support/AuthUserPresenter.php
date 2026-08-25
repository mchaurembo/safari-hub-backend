<?php

namespace App\Support;

use App\Models\User;
use App\Services\BusinessAuthorizationService;
use App\Services\BusinessCapabilityBridgeService;
use App\Services\LegacyBusinessAccessService;

class AuthUserPresenter
{
    /**
     * Enrich a user model for auth responses (login, /me, enroll).
     * Keeps legacy `role` / `roles` while adding capabilities, permissions, workspaces.
     */
    public static function present(User $user): array
    {
        $user->refreshLegacyPrimaryRole();
        $user->loadMissing([
            'role',
            'roles',
            'transportOwner',
            'driver',
            'garages',
            'technicians',
            'garageMemberships',
            'employmentRelationships',
            'activeBusinessMemberships.business.type',
            'activeBusinessMemberships.business.category',
            'activeBusinessMemberships.role',
            'activeBusinessMemberships.position',
        ]);

        $capabilities = $user->capabilitySummaries();
        $bridge = app(BusinessCapabilityBridgeService::class);
        foreach ($bridge->derivedCapabilityCodes($user) as $code) {
            if (! collect($capabilities)->contains(fn ($c) => ($c['code'] ?? null) === $code)) {
                $capabilities[] = [
                    'code' => $code,
                    'status' => 'active',
                    'source' => 'business_membership',
                ];
            }
        }

        $permissions = $user->permissionCodes();
        $workspaces = self::workspaces($user, $capabilities, $bridge);
        $businessMemberships = self::businessMemberships($user);

        $payload = $user->toArray();
        $payload['capabilities'] = $capabilities;
        $payload['permissions'] = $permissions;
        $payload['workspaces'] = $workspaces;
        $payload['business_memberships'] = $businessMemberships;

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
    private static function workspaces(User $user, array $capabilities, BusinessCapabilityBridgeService $bridge): array
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

        $list = self::mergeWorkspaces($list, $bridge->businessWorkspaces($user));

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

    /**
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $overrides
     * @return list<array<string, mixed>>
     */
    private static function mergeWorkspaces(array $base, array $overrides): array
    {
        $indexed = collect($base)->keyBy('id');

        foreach ($overrides as $override) {
            $id = $override['id'] ?? null;
            if (! $id) {
                continue;
            }

            $existing = $indexed->get($id);
            if (! $existing) {
                $indexed->put($id, $override);
                continue;
            }

            if (($override['available'] ?? false) && ! ($existing['available'] ?? false)) {
                $indexed->put($id, array_merge($existing, $override));
            }
        }

        return $indexed->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private static function businessMemberships(User $user): array
    {
        $authorization = app(BusinessAuthorizationService::class);

        return $user->activeBusinessMemberships
            ->map(function ($membership) use ($authorization) {
                $permissions = [];
                try {
                    $permissions = $authorization->effectivePermissions($membership);
                } catch (\Throwable $e) {
                    report($e);
                    $permissions = $membership->isOwner()
                        ? ['business.view', 'product.view', 'product.create', 'order.view', 'order.create', 'business.members.view']
                        : ['business.view'];
                }

                $legacyCapabilities = [];
                try {
                    $legacyCapabilities = app(LegacyBusinessAccessService::class)
                        ->legacyCapabilityCodesForMembership($membership->user_id, $membership->business_id);
                } catch (\Throwable $e) {
                    report($e);
                }

                return [
                    'id' => $membership->id,
                    'uuid' => $membership->uuid,
                    'role' => $membership->role?->code,
                    'role_name' => $membership->role?->name,
                    'position' => $membership->position?->code,
                    'position_name' => $membership->position?->name,
                    'permissions' => $permissions,
                    'legacy_capabilities' => $legacyCapabilities,
                    'business' => [
                        'id' => $membership->business?->id,
                        'uuid' => $membership->business?->uuid,
                        'name' => $membership->business?->displayName(),
                        'type' => $membership->business?->type?->code,
                        'category' => $membership->business?->category?->code,
                        'legacy_transport_owner_id' => $membership->business?->legacy_transport_owner_id,
                        'legacy_garage_id' => $membership->business?->legacy_garage_id,
                    ],
                ];
            })
            ->values()
            ->all();
    }
}
