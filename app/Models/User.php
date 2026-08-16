<?php

namespace App\Models;

use App\Helpers\PhoneHelper;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const CAPABILITY_ACTIVE = 'active';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp_number',
        'password',
        'role_id',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Normalize phone on set (+255, 255, 0, 9-digit → 0XXXXXXXXX) for unique constraint. */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => PhoneHelper::normalize($value),
        );
    }

    /** Normalize whatsapp_number on set for consistency. */
    protected function whatsappNumber(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => PhoneHelper::normalize($value),
        );
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot([
                'status',
                'verification_status',
                'started_at',
                'ended_at',
                'approved_by',
                'approved_at',
            ])
            ->withTimestamps();
    }

    public function transportOwner(): HasOne
    {
        return $this->hasOne(TransportOwner::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'customer_id');
    }

    public function garageBookings(): HasMany
    {
        return $this->hasMany(GarageBooking::class, 'customer_id');
    }

    public function garages(): HasMany
    {
        return $this->hasMany(Garage::class, 'owner_id');
    }

    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class, 'user_id');
    }

    public function garageMemberships(): HasMany
    {
        return $this->hasMany(GarageMember::class);
    }

    public function employmentRelationships(): HasMany
    {
        return $this->hasMany(EmploymentRelationship::class, 'employee_user_id');
    }

    /** Attach a capability without removing others. */
    public function enrollCapability(Role|string $role, string $status = self::CAPABILITY_ACTIVE): void
    {
        $roleModel = $role instanceof Role
            ? $role
            : Role::firstOrCreate(['name' => $role]);

        if ($this->roles()->where('roles.id', $roleModel->id)->exists()) {
            $this->roles()->updateExistingPivot($roleModel->id, [
                'status' => $status,
                'ended_at' => null,
            ]);
            $this->unsetRelation('roles');
            $this->refreshLegacyPrimaryRole();

            return;
        }

        $this->roles()->attach($roleModel->id, [
            'status' => $status,
            'started_at' => now(),
        ]);
        $this->unsetRelation('roles');
        $this->refreshLegacyPrimaryRole();
    }

    /**
     * Preferred display/primary role for legacy `users.role_id` + `user.role` payloads.
     * Capabilities remain the source of truth.
     */
    public function preferredPrimaryRole(): ?Role
    {
        $priority = ['admin', 'owner', 'garage_owner', 'driver', 'technician', 'customer'];
        $codes = $this->activeCapabilityCodes();

        foreach ($priority as $code) {
            if (in_array($code, $codes, true)) {
                return Role::where('name', $code)->first();
            }
        }

        $this->loadMissing('roles');

        return $this->roles->first();
    }

    /**
     * Mirror preferred capability onto legacy role_id (read-compat only).
     */
    public function refreshLegacyPrimaryRole(): void
    {
        $role = $this->preferredPrimaryRole();
        if (! $role) {
            return;
        }

        if ((int) $this->role_id !== (int) $role->id) {
            $this->forceFill(['role_id' => $role->id])->saveQuietly();
            $this->unsetRelation('role');
        }
    }

    public function hasCapability(string $code, string $status = self::CAPABILITY_ACTIVE): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains(function (Role $role) use ($code, $status) {
            if ($role->name !== $code) {
                return false;
            }
            $pivotStatus = $role->pivot->status ?? self::CAPABILITY_ACTIVE;

            return $pivotStatus === $status;
        });
    }

    /** @param  list<string>  $codes */
    public function hasAnyCapability(array $codes, string $status = self::CAPABILITY_ACTIVE): bool
    {
        foreach ($codes as $code) {
            if ($this->hasCapability($code, $status)) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }

    /** @return list<string> */
    public function activeCapabilityCodes(): array
    {
        $this->loadMissing('roles');

        return $this->roles
            ->filter(fn (Role $role) => ($role->pivot->status ?? self::CAPABILITY_ACTIVE) === self::CAPABILITY_ACTIVE)
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{code: string, status: string, verification_status: ?string}>
     */
    public function capabilitySummaries(): array
    {
        $this->loadMissing('roles');

        return $this->roles->map(fn (Role $role) => [
            'code' => $role->name,
            'status' => $role->pivot->status ?? self::CAPABILITY_ACTIVE,
            'verification_status' => $role->pivot->verification_status ?? null,
        ])->values()->all();
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->filter(fn (Role $role) => ($role->pivot->status ?? self::CAPABILITY_ACTIVE) === self::CAPABILITY_ACTIVE)
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->unique()
            ->values()
            ->all();
    }

    public function ownsFleetVehicle(Vehicle $vehicle): bool
    {
        $fleet = $this->transportOwner;

        return $fleet && (int) $vehicle->owner_id === (int) $fleet->id;
    }

    public function ownsGarage(Garage $garage): bool
    {
        if ((int) $garage->owner_id === (int) $this->id) {
            return true;
        }

        return $this->garageMemberships()
            ->where('garage_id', $garage->id)
            ->whereIn('membership_type', [GarageMember::TYPE_OWNER, GarageMember::TYPE_MANAGER])
            ->where('status', 'active')
            ->whereNull('left_at')
            ->exists();
    }

    public function isGarageTechnician(Garage $garage): bool
    {
        if ($this->technicians()
            ->where('garage_id', $garage->id)
            ->where('status', 'active')
            ->exists()) {
            return true;
        }

        return $this->garageMemberships()
            ->where('garage_id', $garage->id)
            ->where('membership_type', GarageMember::TYPE_TECHNICIAN)
            ->where('status', 'active')
            ->whereNull('left_at')
            ->exists();
    }

    public function isGarageMember(Garage $garage, array $types = []): bool
    {
        $query = $this->garageMemberships()
            ->where('garage_id', $garage->id)
            ->where('status', 'active')
            ->whereNull('left_at');

        if ($types) {
            $query->whereIn('membership_type', $types);
        }

        return $query->exists() || (
            in_array(GarageMember::TYPE_OWNER, $types, true) && (int) $garage->owner_id === (int) $this->id
        );
    }
}
