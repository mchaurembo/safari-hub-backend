<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessCapabilityAssignment extends Model
{
    protected $fillable = [
        'business_id',
        'business_capability_id',
        'enabled',
        'config',
        'enabled_at',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            if ($assignment->enabled && ! $assignment->enabled_at) {
                $assignment->enabled_at = now();
            }
            if (! $assignment->enabled) {
                $assignment->disabled_at = now();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(BusinessCapability::class, 'business_capability_id');
    }
}
