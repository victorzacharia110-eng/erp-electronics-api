<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingRule extends Model
{
    protected $fillable = [
        'name',
        'from_city',
        'to_city',
        'base_cost',
        'value_rules',
        'enabled',
    ];

    protected $casts = [
        'base_cost' => 'decimal:2',
        'value_rules' => 'array',
        'enabled' => 'boolean',
    ];

    /**
     * Calculate shipping cost for a given subtotal.
     * Checks value_rules tiers first; falls back to base_cost.
     */
    public function calculateCost(float $subtotal): float
    {
        if (!empty($this->value_rules)) {
            foreach ($this->value_rules as $tier) {
                $min = $tier['min_value'] ?? 0;
                $max = $tier['max_value'] ?? PHP_INT_MAX;
                $adjusted = $tier['adjusted_cost'] ?? $this->base_cost;

                if ($subtotal >= $min && $subtotal <= $max) {
                    return (float) $adjusted;
                }
            }
        }

        return (float) $this->base_cost;
    }
}
