<?php

namespace App\Models;

use App\Services\Payments\PaymentStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

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
        'pickup_lat' => 'float',
        'pickup_lng' => 'float',
        'dest_lat' => 'float',
        'dest_lng' => 'float',
        'distance_km' => 'float',
        'weight_kg' => 'float',
        'quoted_price' => 'float',
        'customer_budget' => 'float',
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

    public function payment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable')->latestOfMany();
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function isPaid(): bool
    {
        return $this->payments()
            ->whereIn('status', PaymentStatuses::successStates())
            ->exists();
    }
}
