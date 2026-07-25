<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    protected $fillable = [
        'owner_id', 'product_variant_id', 'type', 'quantity_change',
        'quantity_after', 'unit_cost', 'reference_type', 'reference_id',
        'notes', 'created_by',
    ];

    protected $casts = [
        'quantity_change' => 'integer',
        'quantity_after' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function productVariant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeForOwner($query, $ownerId) { return $query->where('owner_id', $ownerId); }
}
