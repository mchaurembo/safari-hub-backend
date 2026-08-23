<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Business extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'uuid',
        'legal_name',
        'trade_name',
        'slug',
        'business_category_id',
        'business_type_id',
        'owner_user_id',
        'status',
        'verification_status',
        'tax_id',
        'registration_number',
        'email',
        'phone',
        'website',
        'logo_url',
        'cover_url',
        'timezone',
        'currency',
        'settings',
        'legacy_transport_owner_id',
        'legacy_garage_id',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $business): void {
            if (empty($business->uuid)) {
                $business->uuid = (string) Str::uuid();
            }
            if (empty($business->slug)) {
                $business->slug = Str::slug($business->trade_name ?: $business->legal_name).'-'.Str::lower(Str::random(6));
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(BusinessBranch::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function capabilityAssignments(): HasMany
    {
        return $this->hasMany(BusinessCapabilityAssignment::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(BusinessCustomer::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function transportOwner(): BelongsTo
    {
        return $this->belongsTo(TransportOwner::class, 'legacy_transport_owner_id');
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class, 'legacy_garage_id');
    }

    public function hasCapability(string $code): bool
    {
        return $this->capabilityAssignments()
            ->where('enabled', true)
            ->whereHas('capability', fn ($q) => $q->where('code', $code)->where('is_active', true))
            ->exists();
    }

    public function displayName(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }
}
