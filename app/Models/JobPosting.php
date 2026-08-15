<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    protected $fillable = [
        'transport_owner_id',
        'title',
        'description',
        'transport_type',
        'location',
        'requirements',
        'salary_min',
        'salary_max',
        'status',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(TransportOwner::class, 'transport_owner_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }
}
