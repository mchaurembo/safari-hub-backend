<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessBranch;
use App\Models\BusinessMembership;

class BusinessContext
{
    public function __construct(
        public readonly Business $business,
        public readonly BusinessMembership $membership,
        public readonly ?BusinessBranch $branch = null,
        /** @var list<string> */
        public readonly array $permissions = [],
    ) {}

    public function businessId(): int
    {
        return $this->business->id;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'business_id' => $this->business->id,
            'business_uuid' => $this->business->uuid,
            'business_name' => $this->business->displayName(),
            'branch_id' => $this->branch?->id,
            'branch_name' => $this->branch?->name,
            'membership_id' => $this->membership->id,
            'role' => $this->membership->role?->code,
            'position' => $this->membership->position?->code,
            'permissions' => $this->permissions,
        ];
    }
}
