<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BusinessMembership extends Model
{
    use SoftDeletes;

    public const STATUS_INVITED = 'invited';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    protected $fillable = [
        'uuid',
        'user_id',
        'business_id',
        'membership_role_id',
        'position_id',
        'status',
        'invited_by_membership_id',
        'invited_at',
        'accepted_at',
        'terminated_at',
        'default_branch_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $membership): void {
            if (empty($membership->uuid)) {
                $membership->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(MembershipRole::class, 'membership_role_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(BusinessBranch::class, 'default_branch_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(BusinessMembership::class, 'invited_by_membership_id');
    }

    public function branchAccess(): HasMany
    {
        return $this->hasMany(BusinessMembershipBranch::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(BusinessBranch::class, 'business_membership_branches')
            ->withPivot('access_level')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOwner(): bool
    {
        return $this->role?->code === MembershipRole::CODE_OWNER;
    }

    public function isManager(): bool
    {
        return $this->role?->code === MembershipRole::CODE_MANAGER;
    }
}
