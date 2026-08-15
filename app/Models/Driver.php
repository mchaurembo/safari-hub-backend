<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Driver extends Model
{
    protected $fillable = [
        'user_id', 'owner_id', 'license_number', 'experience_years', 'status'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(TransportOwner::class, 'owner_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'driver_vehicle')->withTimestamps();
    }

    public function location(): HasOne
    {
        return $this->hasOne(DriverLocation::class);
    }

    public function cargoRequests(): HasMany
    {
        return $this->hasMany(CargoRequest::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class);
    }
}
