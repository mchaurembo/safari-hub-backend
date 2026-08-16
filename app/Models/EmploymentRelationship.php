<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmploymentRelationship extends Model
{
    public const EMPLOYER_TRANSPORT = 'transport_owner';
    public const EMPLOYER_GARAGE = 'garage';

    public const TYPE_DRIVER = 'driver';
    public const TYPE_TECHNICIAN = 'technician';
    public const TYPE_STAFF = 'staff';

    protected $fillable = [
        'employer_type',
        'employer_id',
        'employee_user_id',
        'employment_type',
        'position',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }
}
