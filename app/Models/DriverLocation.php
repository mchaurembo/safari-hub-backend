<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLocation extends Model
{
    protected $fillable = ['driver_id', 'latitude', 'longitude', 'is_available'];

    protected $casts = ['is_available' => 'boolean'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
