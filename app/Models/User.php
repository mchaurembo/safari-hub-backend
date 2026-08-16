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
     * @return array<string, string>
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
        // Multi-role support: user_roles pivot holds all roles a user enrolled in.
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
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
        // Garage owner profiles for Garage Services module.
        return $this->hasMany(Garage::class, 'owner_id');
    }

    public function technicians(): HasMany
    {
        // Garage technicians for Garage Services module.
        return $this->hasMany(Technician::class, 'user_id');
    }
}
