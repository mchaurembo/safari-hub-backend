<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessMembershipBranch extends Model
{
    public const ACCESS_READ = 'read';

    public const ACCESS_OPERATE = 'operate';

    public const ACCESS_MANAGE = 'manage';

    protected $fillable = [
        'business_membership_id',
        'business_branch_id',
        'access_level',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(BusinessMembership::class, 'business_membership_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BusinessBranch::class, 'business_branch_id');
    }
}
