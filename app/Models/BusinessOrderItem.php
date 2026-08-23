<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessOrderItem extends Model
{
    protected $fillable = [
        'business_order_id',
        'business_product_id',
        'name',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BusinessOrder::class, 'business_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(BusinessProduct::class, 'business_product_id');
    }
}
