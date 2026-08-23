<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipRole extends Model
{
    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_BUSINESS = 'business';

    public const CODE_PLATFORM_ADMIN = 'platform_administrator';

    public const CODE_OWNER = 'owner';

    public const CODE_MANAGER = 'manager';

    public const CODE_STAFF = 'staff';

    protected $fillable = [
        'scope',
        'code',
        'name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'membership_role_permissions')->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }
}
