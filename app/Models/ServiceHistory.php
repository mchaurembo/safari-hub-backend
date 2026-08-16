<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceHistory extends Model
{
    protected $table = 'service_history';

    protected $fillable = [
        'vehicle_reg',
        'vehicle_id',
        'garage_id',
        'work_order_id',
        'garage_booking_id',
        'customer_id',
        'service_name',
        'summary',
        'amount',
        'serviced_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'serviced_at' => 'datetime',
        ];
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(GarageBooking::class, 'garage_booking_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
