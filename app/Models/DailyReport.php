<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReport extends Model
{
    protected $fillable = [
        'report_date',
        'total_orders',
        'total_revenue',
        'total_items_sold',
        'paid_orders',
        'pending_orders',
        'cancelled_orders',
        'employee_stats',
        'top_products',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'total_revenue' => 'decimal:2',
            'employee_stats' => 'array',
            'top_products' => 'array',
        ];
    }
}
