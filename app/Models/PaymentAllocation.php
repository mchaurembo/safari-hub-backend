<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id', 'recipient_type', 'recipient_id', 'allocation_type',
        'gross_amount_minor', 'commission_amount_minor', 'net_amount_minor',
        'currency', 'status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount_minor' => 'integer',
            'commission_amount_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}
