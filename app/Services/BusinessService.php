<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\BusinessCapability;
use App\Models\BusinessCapabilityAssignment;
use App\Models\BusinessMembership;
use App\Models\BusinessProfile;
use App\Models\BusinessType;
use App\Models\GarageMember;
use App\Models\MembershipRole;
use App\Models\Position;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessService
{
    public function __construct(
        private readonly BusinessCapabilityBridgeService $capabilityBridge,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(User $user, BusinessType $type, array $data): Business
    {
        return DB::transaction(function () use ($user, $type, $data) {
            $type->loadMissing('category');

            $business = Business::create([
                'legal_name' => $data['legal_name'],
                'trade_name' => $data['trade_name'] ?? $data['legal_name'],
                'slug' => $data['slug'] ?? Str::slug($data['trade_name'] ?? $data['legal_name']).'-'.Str::lower(Str::random(6)),
                'business_category_id' => $type->business_category_id,
                'business_type_id' => $type->id,
                'owner_user_id' => $user->id,
                'status' => Business::STATUS_ACTIVE,
                'verification_status' => 'verified',
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'] ?? $user->phone,
            ]);

            BusinessProfile::create([
                'business_id' => $business->id,
                'description' => $data['description'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'region' => $data['region'] ?? null,
            ]);

            $branch = BusinessBranch::create([
                'business_id' => $business->id,
                'name' => $data['branch_name'] ?? 'Head Office',
                'code' => 'hq',
                'is_head_office' => true,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'status' => BusinessBranch::STATUS_ACTIVE,
            ]);

            $this->assignDefaultCapabilities($business, $type);

            $ownerRole = MembershipRole::where('scope', MembershipRole::SCOPE_BUSINESS)
                ->where('code', MembershipRole::CODE_OWNER)
                ->firstOrFail();

            BusinessMembership::create([
                'user_id' => $user->id,
                'business_id' => $business->id,
                'membership_role_id' => $ownerRole->id,
                'status' => BusinessMembership::STATUS_ACTIVE,
                'accepted_at' => now(),
                'default_branch_id' => $branch->id,
            ]);

            $this->capabilityBridge->syncLegacyCapabilities($user->fresh());

            return $business->fresh(['category', 'type', 'profile', 'branches', 'capabilityAssignments.capability']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addMember(
        Business $business,
        User $employee,
        MembershipRole $role,
        ?int $positionId = null,
        ?BusinessMembership $invitedBy = null,
    ): BusinessMembership {
        return BusinessMembership::updateOrCreate(
            [
                'user_id' => $employee->id,
                'business_id' => $business->id,
            ],
            [
                'membership_role_id' => $role->id,
                'position_id' => $positionId,
                'status' => BusinessMembership::STATUS_ACTIVE,
                'invited_by_membership_id' => $invitedBy?->id,
                'invited_at' => $invitedBy ? now() : null,
                'accepted_at' => now(),
                'terminated_at' => null,
            ]
        )->tap(function () use ($employee) {
            $this->capabilityBridge->syncLegacyCapabilities($employee->fresh());
        });
    }

    public function ensureStaffMembership(
        Business $business,
        User $employee,
        string $positionCode,
        string $membershipRoleCode = MembershipRole::CODE_STAFF,
    ): BusinessMembership {
        $business->loadMissing('type');

        $role = MembershipRole::where('scope', MembershipRole::SCOPE_BUSINESS)
            ->where('code', $membershipRoleCode)
            ->firstOrFail();

        $position = Position::query()
            ->where('code', $positionCode)
            ->where(function ($q) use ($business) {
                $q->whereNull('business_id')
                    ->orWhere('business_id', $business->id);
            })
            ->where(function ($q) use ($business) {
                $q->whereNull('business_type_id')
                    ->orWhere('business_type_id', $business->business_type_id);
            })
            ->first();

        return $this->addMember($business, $employee, $role, $position?->id);
    }

    public function terminateMembership(Business $business, User $user): void
    {
        BusinessMembership::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->update([
                'status' => BusinessMembership::STATUS_TERMINATED,
                'terminated_at' => now(),
            ]);
    }

    /**
     * Pause a business: keep owner membership, stop staff operations.
     * Staff stay suspended until the owner resumes them manually after resumeOperations().
     *
     * @return array{suspended_members: int, suspended_technicians: int}
     */
    public function pauseOperations(Business $business, User $actor): array
    {
        $this->assertOwnerActor($business, $actor);

        return DB::transaction(function () use ($business) {
            $business->update(['status' => Business::STATUS_SUSPENDED]);

            $staffQuery = BusinessMembership::query()
                ->where('business_id', $business->id)
                ->where('status', BusinessMembership::STATUS_ACTIVE)
                ->whereHas('role', fn ($q) => $q->where('code', '!=', MembershipRole::CODE_OWNER));

            $staffIds = (clone $staffQuery)->pluck('id');
            $suspendedMembers = 0;
            foreach ($staffIds as $membershipId) {
                $membership = BusinessMembership::find($membershipId);
                if (! $membership) {
                    continue;
                }
                $meta = is_array($membership->metadata) ? $membership->metadata : [];
                $meta['paused_by_business'] = true;
                $membership->update([
                    'status' => BusinessMembership::STATUS_SUSPENDED,
                    'metadata' => $meta,
                ]);
                $suspendedMembers++;
            }

            $suspendedTechnicians = 0;
            if ($business->legacy_garage_id) {
                $suspendedTechnicians = Technician::query()
                    ->where('garage_id', $business->legacy_garage_id)
                    ->whereIn('status', ['active', 'busy'])
                    ->update(['status' => 'inactive']);

                GarageMember::query()
                    ->where('garage_id', $business->legacy_garage_id)
                    ->where('status', 'active')
                    ->whereNull('left_at')
                    ->update(['status' => 'inactive']);
            }

            return [
                'suspended_members' => $suspendedMembers,
                'suspended_technicians' => (int) $suspendedTechnicians,
            ];
        });
    }

    /**
     * Resume business operations. Staff remain suspended until reactivated manually.
     */
    public function resumeOperations(Business $business, User $actor): void
    {
        $this->assertOwnerActor($business, $actor);

        if ($business->status === Business::STATUS_ACTIVE) {
            return;
        }

        $business->update(['status' => Business::STATUS_ACTIVE]);
    }

    /**
     * Pause every business the user owns (optionally filtered by legacy workspace role).
     *
     * @return list<array{business_id: int, name: string, suspended_members: int}>
     */
    public function pauseOwnedBusinesses(User $user, ?string $legacyRole = null): array
    {
        $query = Business::query()->where('owner_user_id', $user->id)
            ->where('status', Business::STATUS_ACTIVE);

        if ($legacyRole === 'owner') {
            $query->whereNotNull('legacy_transport_owner_id');
        } elseif ($legacyRole === 'garage_owner') {
            $query->whereNotNull('legacy_garage_id');
        }

        $results = [];
        foreach ($query->get() as $business) {
            $stats = $this->pauseOperations($business, $user);
            $results[] = [
                'business_id' => $business->id,
                'name' => $business->displayName(),
                'suspended_members' => $stats['suspended_members'],
            ];
        }

        return $results;
    }

    private function assertOwnerActor(Business $business, User $actor): void
    {
        $isOwner = (int) $business->owner_user_id === (int) $actor->id
            || BusinessMembership::query()
                ->where('business_id', $business->id)
                ->where('user_id', $actor->id)
                ->where('status', BusinessMembership::STATUS_ACTIVE)
                ->whereHas('role', fn ($q) => $q->where('code', MembershipRole::CODE_OWNER))
                ->exists();

        if (! $isOwner) {
            throw ValidationException::withMessages([
                'business' => ['Only the business owner can pause or resume operations.'],
            ]);
        }
    }

    private function assignDefaultCapabilities(Business $business, BusinessType $type): void
    {
        $codes = $type->default_capability_codes ?? [];

        foreach ($codes as $code) {
            $capability = BusinessCapability::where('code', $code)->where('is_active', true)->first();
            if (! $capability) {
                continue;
            }

            BusinessCapabilityAssignment::create([
                'business_id' => $business->id,
                'business_capability_id' => $capability->id,
                'enabled' => true,
                'enabled_at' => now(),
            ]);
        }
    }
}
