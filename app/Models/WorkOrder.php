<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkOrder extends Model
{
    use BelongsToBusiness;

    public const STATUSES = [
        'open',
        'assigned',
        'diagnosing',
        'waiting_approval',
        'in_progress',
        'quality_check',
        'completed',
        'closed',
        'cancelled',
    ];

    protected $fillable = [
        'business_id',
        'garage_booking_id',
        'garage_id',
        'customer_id',
        'technician_id',
        'service_id',
        'vehicle_reg',
        'status',
        'diagnosis',
        'notes',
        'labour_total',
        'parts_total',
        'total_amount',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'labour_total' => 'decimal:2',
            'parts_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(GarageBooking::class, 'garage_booking_id');
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(GarageService::class, 'service_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    public function serviceHistory(): HasOne
    {
        return $this->hasOne(ServiceHistory::class);
    }

    public function recalculateTotals(): void
    {
        $labour = (float) $this->items()->where('item_type', 'labour')->sum('line_total');
        $parts = (float) $this->items()->where('item_type', 'part')->sum('line_total');
        $other = (float) $this->items()->whereNotIn('item_type', ['labour', 'part'])->sum('line_total');

        $this->update([
            'labour_total' => $labour,
            'parts_total' => $parts,
            'total_amount' => $labour + $parts + $other,
        ]);
    }
}
