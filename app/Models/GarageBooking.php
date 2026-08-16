<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GarageBooking extends Model
{
    public const STATUSES = [
        'pending',
        'confirmed',
        'assigned',
        'in_progress',
        'completed',
        'cancelled',
    ];

    protected $fillable = [
        'customer_id',
        'garage_id',
        'service_id',
        'technician_id',
        'vehicle_reg',
        'notes',
        'amount',
        'status',
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(GarageService::class, 'service_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(BookingUpdate::class, 'booking_id');
    }
}
