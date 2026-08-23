<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Technician extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'user_id',
        'garage_id',
        'specialization',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class, 'garage_id');
    }

    public function bookings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GarageBooking::class);
    }
}

