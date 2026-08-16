<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingUpdate extends Model
{
    protected $fillable = [
        'booking_id',
        'created_by',
        'comment',
        'status',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(GarageBooking::class, 'booking_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
