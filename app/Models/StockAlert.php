<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAlert extends Model
{
    protected $fillable = [
        'owner_id', 'product_variant_id', 'type', 'status',
        'current_quantity', 'reorder_level', 'message',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function productVariant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
    public function scopeForOwner($query, $ownerId) { return $query->where('owner_id', $ownerId); }
    public function scopeActive($query) { return $query->where('status', 'active'); }
}
