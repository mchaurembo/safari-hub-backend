<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
    protected $fillable = [
        'code', 'name', 'driver', 'status', 'is_default', 'priority',
        'supported_methods', 'configuration',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'supported_methods' => 'array',
            'configuration' => 'array',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'gateway_id');
    }
}
