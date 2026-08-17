<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id', 'type', 'reference', 'credit_minor', 'debit_minor',
        'balance_after_minor', 'source_type', 'source_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'credit_minor' => 'integer',
            'debit_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
