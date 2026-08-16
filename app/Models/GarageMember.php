<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarageMember extends Model
{
    public const TYPE_OWNER = 'owner';
    public const TYPE_MANAGER = 'manager';
    public const TYPE_TECHNICIAN = 'technician';
    public const TYPE_RECEPTIONIST = 'receptionist';
    public const TYPE_ACCOUNTANT = 'accountant';

    protected $fillable = [
        'garage_id',
        'user_id',
        'membership_type',
        'status',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function garage(): BelongsTo
    {
        return $this->belongsTo(Garage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->left_at === null;
    }
}
