<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCategory extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function types(): HasMany
    {
        return $this->hasMany(BusinessType::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }
}
