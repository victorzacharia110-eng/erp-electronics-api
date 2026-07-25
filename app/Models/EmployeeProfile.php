<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProfile extends Model
{
    protected $fillable = [
        'user_id',
        'branch_id',
        'employee_code',
        'position',
        'department',
        'hire_date',
        'commission_rate',
        'base_salary',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'commission_rate' => 'decimal:2',
            'base_salary' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
