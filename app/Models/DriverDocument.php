<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DriverDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'driver_id',
        'document_type',
        'label',
        'file_path',
        'original_name',
        'mime_type',
        'expiry_date',
        'verified',
    ];

    protected $appends = ['url'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }
}
