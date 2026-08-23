<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCustomer extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'business_id',
        'user_id',
        'customer_number',
        'status',
        'first_interaction_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'first_interaction_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
