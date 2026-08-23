<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Garage extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'owner_id',
        'name',
        'location',
        'status',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(GarageService::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(GarageBooking::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GarageMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active')->whereNull('left_at');
    }
}

