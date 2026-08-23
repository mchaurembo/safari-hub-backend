<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\BusinessCapability;
use App\Models\BusinessCapabilityAssignment;
use App\Models\BusinessMembership;
use App\Models\BusinessProfile;
use App\Models\BusinessType;
use App\Models\EmploymentRelationship;
use App\Models\Garage;
use App\Models\GarageMember;
use App\Models\MembershipRole;
use App\Models\Position;
use App\Models\TransportOwner;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BusinessBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BusinessPlatformSeeder::class);

        $transportType = BusinessType::where('code', 'passenger_transport')->firstOrFail();
        $garageType = BusinessType::where('code', 'garage')->firstOrFail();
        $ownerRole = MembershipRole::where('code', MembershipRole::CODE_OWNER)->firstOrFail();
        $managerRole = MembershipRole::where('code', MembershipRole::CODE_MANAGER)->firstOrFail();
        $staffRole = MembershipRole::where('code', MembershipRole::CODE_STAFF)->firstOrFail();

        foreach (TransportOwner::with('user')->get() as $fleet) {
            if (Business::where('legacy_transport_owner_id', $fleet->id)->exists()) {
                continue;
            }

            $business = Business::create([
                'legal_name' => $fleet->company_name ?: 'Transport Business',
                'trade_name' => $fleet->company_name,
                'slug' => Str::slug($fleet->company_name ?: 'transport').'-fleet-'.$fleet->id,
                'business_category_id' => $transportType->business_category_id,
                'business_type_id' => $transportType->id,
                'owner_user_id' => $fleet->user_id,
                'status' => $fleet->status === 'approved' ? Business::STATUS_ACTIVE : Business::STATUS_PENDING_VERIFICATION,
                'legacy_transport_owner_id' => $fleet->id,
            ]);

            $this->seedBusinessBasics($business, $transportType, $fleet->address);
            $this->createOwnerMembership($business, $fleet->user_id, $ownerRole);

            EmploymentRelationship::where('employer_type', EmploymentRelationship::EMPLOYER_TRANSPORT)
                ->where('employer_id', $fleet->id)
                ->where('status', 'active')
                ->each(function (EmploymentRelationship $rel) use ($business, $managerRole, $staffRole) {
                    $role = $rel->position === 'manager' ? $managerRole : $staffRole;
                    $position = Position::where('code', $rel->employment_type === 'driver' ? 'driver' : 'fleet_officer')
                        ->where('business_type_id', $business->business_type_id)
                        ->first();

                    BusinessMembership::updateOrCreate(
                        ['user_id' => $rel->employee_user_id, 'business_id' => $business->id],
                        [
                            'membership_role_id' => $role->id,
                            'position_id' => $position?->id,
                            'status' => BusinessMembership::STATUS_ACTIVE,
                            'accepted_at' => now(),
                        ]
                    );
                });
        }

        foreach (Garage::with('owner')->get() as $garage) {
            if (Business::where('legacy_garage_id', $garage->id)->exists()) {
                continue;
            }

            $business = Business::create([
                'legal_name' => $garage->name,
                'trade_name' => $garage->name,
                'slug' => Str::slug($garage->name ?: 'garage').'-'.$garage->id,
                'business_category_id' => $garageType->business_category_id,
                'business_type_id' => $garageType->id,
                'owner_user_id' => $garage->owner_id,
                'status' => $garage->status === 'active' ? Business::STATUS_ACTIVE : Business::STATUS_DRAFT,
                'legacy_garage_id' => $garage->id,
            ]);

            $this->seedBusinessBasics($business, $garageType, $garage->location);
            $this->createOwnerMembership($business, $garage->owner_id, $ownerRole);

            GarageMember::where('garage_id', $garage->id)
                ->where('status', 'active')
                ->whereNull('left_at')
                ->each(function (GarageMember $member) use ($business, $managerRole, $staffRole, $garageType) {
                    if ((int) $member->user_id === (int) $business->owner_user_id) {
                        return;
                    }

                    $role = match ($member->membership_type) {
                        GarageMember::TYPE_MANAGER => $managerRole,
                        default => $staffRole,
                    };

                    $positionCode = match ($member->membership_type) {
                        GarageMember::TYPE_MANAGER => null,
                        GarageMember::TYPE_TECHNICIAN => 'technician',
                        default => null,
                    };

                    $position = $positionCode
                        ? Position::where('code', $positionCode)->where('business_type_id', $garageType->id)->first()
                        : null;

                    BusinessMembership::updateOrCreate(
                        ['user_id' => $member->user_id, 'business_id' => $business->id],
                        [
                            'membership_role_id' => $role->id,
                            'position_id' => $position?->id,
                            'status' => BusinessMembership::STATUS_ACTIVE,
                            'accepted_at' => now(),
                        ]
                    );
                });
        }
    }

    private function seedBusinessBasics(Business $business, BusinessType $type, ?string $address): void
    {
        BusinessProfile::firstOrCreate(
            ['business_id' => $business->id],
            ['address' => $address]
        );

        BusinessBranch::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'hq'],
            [
                'name' => 'Head Office',
                'is_head_office' => true,
                'address' => $address,
                'status' => BusinessBranch::STATUS_ACTIVE,
            ]
        );

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
    }

    private function createOwnerMembership(Business $business, ?int $userId, MembershipRole $ownerRole): void
    {
        if (! $userId) {
            return;
        }

        $branch = $business->branches()->where('code', 'hq')->first();

        BusinessMembership::firstOrCreate(
            ['user_id' => $userId, 'business_id' => $business->id],
            [
                'membership_role_id' => $ownerRole->id,
                'status' => BusinessMembership::STATUS_ACTIVE,
                'accepted_at' => now(),
                'default_branch_id' => $branch?->id,
            ]
        );
    }
}
