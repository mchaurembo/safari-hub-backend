<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessMembershipInvitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid',
        'token_hash',
        'business_id',
        'email',
        'phone',
        'membership_role_id',
        'position_id',
        'branch_ids',
        'invited_by_membership_id',
        'status',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'branch_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $invitation): void {
            if (empty($invitation->uuid)) {
                $invitation->uuid = (string) Str::uuid();
            }
        });
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

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(BusinessMembership::class, 'invited_by_membership_id');
    }
}
