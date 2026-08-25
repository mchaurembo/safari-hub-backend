<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GarageService extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'garage_id',
        'name',
        'service_catalog_item_id',
        'description',
        'price',
        'type',
        'duration_minutes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
        ];
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_catalog_item_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(GarageBooking::class, 'service_id');
    }
}
