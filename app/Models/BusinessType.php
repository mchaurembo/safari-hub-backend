<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessType extends Model
{
    protected $fillable = [
        'business_category_id',
        'code',
        'name',
        'description',
        'default_capability_codes',
        'onboarding_template',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_capability_codes' => 'array',
            'onboarding_template' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id');
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
