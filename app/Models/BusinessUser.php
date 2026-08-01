<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BusinessUser extends Pivot
{
    public $incrementing = false;

    protected $table = 'business_user';

    protected $fillable = [
        'business_id',
        'user_id',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
