<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessProduct extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'business_id',
        'business_product_category_id',
        'product_catalog_item_id',
        'sku',
        'name',
        'description',
        'price',
        'currency',
        'stock_quantity',
        'unit',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BusinessProductCategory::class, 'business_product_category_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ProductCatalogItem::class, 'product_catalog_item_id');
    }
}
