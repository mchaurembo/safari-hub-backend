<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id', 'owner_id', 'vehicle_number', 'vehicle_type', 'total_seats', 'model', 'status', 'transport_type'
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(TransportOwner::class, 'owner_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'driver_vehicle')->withTimestamps();
    }
}
