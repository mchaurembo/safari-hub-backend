<?php

namespace App\Services;

use App\Models\Garage;
use App\Models\GarageMember;
use App\Models\Technician;
use App\Models\TransportOwner;
use App\Models\User;
use App\Support\BusinessContext;
use Illuminate\Http\Request;

/**
 * Bridges legacy fleet/garage routes with CHAPA business context (X-Business-Id).
 * When a user belongs to multiple businesses, the active business selects the legacy record.
 */
class LegacyBusinessAccessService
{
    public function __construct(
        private readonly BusinessAuthorizationService $authorization,
    ) {}

    public function activeBusinessIdFromRequest(Request $request): ?int
    {
        $context = $request->attributes->get('business_context');
        if ($context instanceof BusinessContext) {
            return $context->businessId();
        }

        $header = $request->header('X-Business-Id');
        if ($header !== null && $header !== '') {
            return (int) $header;
        }

        return null;
    }

    public function transportFleetForRequest(User $user, Request $request): ?TransportOwner
    {
        $businessId = $this->activeBusinessIdFromRequest($request);
        if ($businessId) {
            $membership = $this->authorization->resolveMembership($user, $businessId);
            if ($membership?->business?->legacy_transport_owner_id) {
                $fleet = TransportOwner::find($membership->business->legacy_transport_owner_id);
                if ($fleet && $this->userCanAccessFleet($user, $fleet, $membership)) {
                    return $fleet;
                }
            }
        }

        return $user->accessibleTransportFleet();
    }

    public function garageForRequest(User $user, Request $request): ?Garage
    {
        $businessId = $this->activeBusinessIdFromRequest($request);
        if ($businessId) {
            $membership = $this->authorization->resolveMembership($user, $businessId);
            if ($membership?->business?->legacy_garage_id) {
                $garage = Garage::find($membership->business->legacy_garage_id);
                if ($garage && $this->userCanAccessGarage($user, $garage, $membership)) {
                    return $garage;
                }
            }
        }

        return $this->defaultGarageForUser($user);
    }

    public function requireOwnerGarage(User $user, Request $request): Garage
    {
        $garage = $this->garageForRequest($user, $request);
        abort_unless($garage && $user->ownsGarage($garage), 403, 'Garage owner access required.');

        return $garage;
    }

    public function requireGarageAccess(User $user, Request $request): Garage
    {
        $garage = $this->garageForRequest($user, $request);
        abort_unless($garage, 403, 'Garage access required.');

        return $garage;
    }

    /** @return list<string> */
    public function legacyCapabilityCodesForMembership(int $userId, int $businessId): array
    {
        $membership = $this->authorization->resolveMembership(
            User::find($userId) ?? new User(['id' => $userId]),
            $businessId,
        );
        if (! $membership) {
            return [];
        }

        $type = $membership->business?->type?->code;
        $role = $membership->role?->code;
        $position = $membership->position?->code;

        if (in_array($type, ['passenger_transport', 'logistics'], true)) {
            return match (true) {
                $role === 'owner' => ['owner'],
                $role === 'manager' => ['transport_manager'],
                $position === 'driver' => ['driver'],
                default => [],
            };
        }

        if (in_array($type, ['garage', 'car_wash'], true)) {
            return match (true) {
                $role === 'owner' => ['garage_owner'],
                $role === 'manager' => ['garage_manager'],
                in_array($position, ['technician', 'mechanic'], true) => ['technician'],
                default => [],
            };
        }

        return [];
    }

    private function userCanAccessFleet(User $user, TransportOwner $fleet, $membership): bool
    {
        if ((int) $fleet->user_id === (int) $user->id) {
            return true;
        }

        $perms = $this->authorization->effectivePermissions($membership);

        return array_intersect($perms, ['vehicle.view', 'trip.manage', 'driver.view']) !== [];
    }

    private function userCanAccessGarage(User $user, Garage $garage, $membership): bool
    {
        if ($user->ownsGarage($garage) || $user->isGarageTechnician($garage)) {
            return true;
        }

        $perms = $this->authorization->effectivePermissions($membership);

        return array_intersect($perms, ['garage.view', 'work_order.view', 'garage_booking.view']) !== [];
    }

    private function defaultGarageForUser(User $user): ?Garage
    {
        $owned = Garage::where('owner_id', $user->id)->first();
        if ($owned) {
            return $owned;
        }

        $managed = $user->garageMemberships()
            ->whereIn('membership_type', [GarageMember::TYPE_OWNER, GarageMember::TYPE_MANAGER])
            ->where('status', 'active')
            ->whereNull('left_at')
            ->with('garage')
            ->latest('id')
            ->first();

        return $managed?->garage ?? Technician::where('user_id', $user->id)
            ->whereIn('status', ['active', 'busy'])
            ->with('garage')
            ->latest('id')
            ->first()
            ?->garage;
    }
}
