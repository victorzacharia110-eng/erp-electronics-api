<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'product_variant_id',
        'quantity_on_hand',
        'reorder_level',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'integer',
            'reorder_level' => 'integer',
        ];
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function isInStock(): bool
    {
        return $this->quantity_on_hand > 0;
    }

    public function needsReorder(): bool
    {
        return $this->quantity_on_hand <= $this->reorder_level;
    }
}
