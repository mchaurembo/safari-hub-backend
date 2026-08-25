<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCatalogCategory extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'applies_to',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'applies_to' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceCatalogItem::class);
    }
}
