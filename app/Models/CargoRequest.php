<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CargoRequest extends Model
{
    protected $fillable = [
        'customer_id', 'driver_id', 'vehicle_id',
        'pickup_lat', 'pickup_lng', 'pickup_address',
        'dest_lat', 'dest_lng', 'dest_address',
        'distance_km', 'cargo_description', 'weight_kg',
        'quoted_price', 'customer_budget', 'status', 'notes',
    ];

    protected $casts = [
        'pickup_lat'  => 'float',
        'pickup_lng'  => 'float',
        'dest_lat'    => 'float',
        'dest_lng'    => 'float',
        'distance_km' => 'float',
        'weight_kg'   => 'float',
        'quoted_price'     => 'float',
        'customer_budget'  => 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
