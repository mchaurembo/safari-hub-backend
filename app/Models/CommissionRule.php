<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRule extends Model
{
    protected $fillable = [
        'code', 'name', 'module', 'status', 'calculation_type',
        'platform_rate', 'platform_fixed_minor', 'recipient_rules',
    ];

    protected function casts(): array
    {
        return [
            'platform_rate' => 'decimal:4',
            'platform_fixed_minor' => 'integer',
            'recipient_rules' => 'array',
        ];
    }
}
