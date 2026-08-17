<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = [
        'payout_reference', 'recipient_id', 'payment_id', 'wallet_id',
        'amount_minor', 'currency', 'payout_method', 'gateway_id',
        'gateway_reference', 'status', 'requested_at', 'processed_at',
        'failed_at', 'failure_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class, 'wallet_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }
}
