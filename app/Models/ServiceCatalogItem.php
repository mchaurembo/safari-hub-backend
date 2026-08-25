<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCatalogItem extends Model
{
    protected $fillable = [
        'service_catalog_category_id',
        'code',
        'name',
        'description',
        'default_pricing_type',
        'default_duration_minutes',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_duration_minutes' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogCategory::class, 'service_catalog_category_id');
    }
}
