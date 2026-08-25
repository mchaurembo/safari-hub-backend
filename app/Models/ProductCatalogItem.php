<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCatalogItem extends Model
{
    protected $fillable = [
        'product_catalog_category_id',
        'code',
        'name',
        'description',
        'default_unit',
        'sku_hint',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCatalogCategory::class, 'product_catalog_category_id');
    }
}
