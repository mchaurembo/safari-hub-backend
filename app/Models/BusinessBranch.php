<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BusinessBranch extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'uuid',
        'business_id',
        'name',
        'code',
        'is_head_office',
        'address',
        'city',
        'region',
        'country',
        'latitude',
        'longitude',
        'phone',
        'email',
        'status',
        'operating_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_head_office' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'operating_hours' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $branch): void {
            if (empty($branch->uuid)) {
                $branch->uuid = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembershipBranch::class);
    }
}
