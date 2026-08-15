<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportOwner extends Model
{
    protected $table = 'transport_owners';

    protected $fillable = [
        'user_id', 'company_name', 'license_number', 'address', 'status'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class, 'owner_id');
    }
}
