<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    protected $fillable = ['origin', 'destination', 'distance', 'estimated_time'];

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id');
    }
}
