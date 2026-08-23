<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    protected $primaryKey = 'business_id';

    public $incrementing = false;

    protected $fillable = [
        'business_id',
        'description',
        'address',
        'city',
        'region',
        'country',
        'latitude',
        'longitude',
        'operating_hours',
        'social_links',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'operating_hours' => 'array',
            'social_links' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
