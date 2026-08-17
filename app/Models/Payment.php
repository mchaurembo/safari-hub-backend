<?php

namespace App\Models;

use App\Services\Payments\PaymentStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $fillable = [
        'payment_reference',
        'payer_id',
        'booking_id',
        'payable_type',
        'payable_id',
        'transaction_type',
        'amount',
        'currency',
        'amount_minor',
        'payment_method',
        'payment_method_id',
        'gateway_id',
        'transaction_reference',
        'gateway_reference',
        'idempotency_key',
        'status',
        'payment_url',
        'initiated_at',
        'processing_at',
        'paid_at',
        'failed_at',
        'expired_at',
        'failure_reason',
        'metadata',
        'successful_attempt_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'initiated_at' => 'datetime',
            'processing_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function successfulAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'successful_attempt_id');
    }

    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', PaymentStatuses::successStates());
    }

    public function isSuccessful(): bool
    {
        return PaymentStatuses::isTerminalSuccess((string) $this->status);
    }
}
