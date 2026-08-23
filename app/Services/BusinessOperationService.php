<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\BusinessCapability;
use App\Models\BusinessCapabilityAssignment;
use App\Models\BusinessMembership;
use App\Models\BusinessProfile;
use App\Models\BusinessType;
use App\Models\Driver;
use App\Models\Garage;
use App\Models\GarageBooking;
use App\Models\GarageService;
use App\Models\MembershipRole;
use App\Models\Technician;
use App\Models\TransportOwner;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Support\Str;

class BusinessOperationService
{
    public function idForTransportOwner(int $transportOwnerId): ?int
    {
        return Business::where('legacy_transport_owner_id', $transportOwnerId)->value('id')
            ?? TransportOwner::where('id', $transportOwnerId)->value('business_id');
    }

    public function idForGarage(int $garageId): ?int
    {
        return Business::where('legacy_garage_id', $garageId)->value('id')
            ?? Garage::where('id', $garageId)->value('business_id');
    }

    public function ensureBusinessForFleet(TransportOwner $fleet): ?int
    {
        $existing = $this->idForTransportOwner($fleet->id);
        if ($existing) {
            if (! $fleet->business_id) {
                $fleet->forceFill(['business_id' => $existing])->saveQuietly();
            }

            return $existing;
        }

        if ($fleet->business_id) {
            return $fleet->business_id;
        }

        if (! $fleet->user_id) {
            return null;
        }

        $type = BusinessType::where('code', 'passenger_transport')->first();
        if (! $type) {
            return null;
        }

        $business = $this->createBusinessFromLegacy(
            type: $type,
            ownerUserId: $fleet->user_id,
            legalName: $fleet->company_name ?: 'Transport Business',
            address: $fleet->address,
            legacyTransportOwnerId: $fleet->id,
        );

        $fleet->forceFill(['business_id' => $business->id])->saveQuietly();
        $this->syncFleetOperations($fleet->id, $business->id);

        return $business->id;
    }

    public function ensureBusinessForGarage(Garage $garage): ?int
    {
        $existing = $this->idForGarage($garage->id);
        if ($existing) {
            if (! $garage->business_id) {
                $garage->forceFill(['business_id' => $existing])->saveQuietly();
            }

            return $existing;
        }

        if ($garage->business_id) {
            return $garage->business_id;
        }

        if (! $garage->owner_id) {
            return null;
        }

        $type = BusinessType::where('code', 'garage')->first();
        if (! $type) {
            return null;
        }

        $business = $this->createBusinessFromLegacy(
            type: $type,
            ownerUserId: $garage->owner_id,
            legalName: $garage->name,
            address: $garage->location,
            legacyGarageId: $garage->id,
        );

        $garage->forceFill(['business_id' => $business->id])->saveQuietly();
        $this->syncGarageOperations($garage->id, $business->id);

        return $business->id;
    }

    public function syncFleetOperations(int $transportOwnerId, int $businessId): void
    {
        Vehicle::where('owner_id', $transportOwnerId)->whereNull('business_id')
            ->update(['business_id' => $businessId]);
        Driver::where('owner_id', $transportOwnerId)->whereNull('business_id')
            ->update(['business_id' => $businessId]);

        $vehicleIds = Vehicle::where('owner_id', $transportOwnerId)->pluck('id');
        if ($vehicleIds->isNotEmpty()) {
            Trip::whereIn('vehicle_id', $vehicleIds)->whereNull('business_id')
                ->update(['business_id' => $businessId]);
        }
    }

    public function syncGarageOperations(int $garageId, int $businessId): void
    {
        GarageService::where('garage_id', $garageId)->whereNull('business_id')
            ->update(['business_id' => $businessId]);
        Technician::where('garage_id', $garageId)->whereNull('business_id')
            ->update(['business_id' => $businessId]);
        GarageBooking::where('garage_id', $garageId)->whereNull('business_id')
            ->update(['business_id' => $businessId]);
        WorkOrder::where('garage_id', $garageId)->whereNull('business_id')
            ->update(['business_id' => $businessId]);
    }

    public function businessIdForNewVehicle(int $transportOwnerId): ?int
    {
        return $this->idForTransportOwner($transportOwnerId);
    }

    public function businessIdForNewGarageBooking(int $garageId): ?int
    {
        return $this->idForGarage($garageId);
    }

    public function businessIdForGarage(int $garageId): ?int
    {
        return $this->idForGarage($garageId);
    }

    private function createBusinessFromLegacy(
        BusinessType $type,
        int $ownerUserId,
        string $legalName,
        ?string $address = null,
        ?int $legacyTransportOwnerId = null,
        ?int $legacyGarageId = null,
    ): Business {
        $business = Business::create([
            'legal_name' => $legalName,
            'trade_name' => $legalName,
            'slug' => Str::slug($legalName).'-'.Str::lower(Str::random(6)),
            'business_category_id' => $type->business_category_id,
            'business_type_id' => $type->id,
            'owner_user_id' => $ownerUserId,
            'status' => Business::STATUS_ACTIVE,
            'legacy_transport_owner_id' => $legacyTransportOwnerId,
            'legacy_garage_id' => $legacyGarageId,
        ]);

        BusinessProfile::create([
            'business_id' => $business->id,
            'address' => $address,
        ]);

        BusinessBranch::create([
            'business_id' => $business->id,
            'name' => 'Head Office',
            'code' => 'hq',
            'is_head_office' => true,
            'address' => $address,
            'status' => BusinessBranch::STATUS_ACTIVE,
        ]);

        foreach ($type->default_capability_codes ?? [] as $code) {
            $capability = BusinessCapability::where('code', $code)->first();
            if (! $capability) {
                continue;
            }
            BusinessCapabilityAssignment::firstOrCreate(
                ['business_id' => $business->id, 'business_capability_id' => $capability->id],
                ['enabled' => true, 'enabled_at' => now()]
            );
        }

        $ownerRole = MembershipRole::where('code', MembershipRole::CODE_OWNER)->first();
        if ($ownerRole) {
            BusinessMembership::firstOrCreate(
                ['user_id' => $ownerUserId, 'business_id' => $business->id],
                [
                    'membership_role_id' => $ownerRole->id,
                    'status' => BusinessMembership::STATUS_ACTIVE,
                    'accepted_at' => now(),
                ]
            );
            $owner = User::find($ownerUserId);
            if ($owner) {
                app(BusinessCapabilityBridgeService::class)->syncLegacyCapabilities($owner);
            }
        }

        return $business;
    }
}
