<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'owner_id', 'po_number', 'supplier_name', 'supplier_contact',
        'status', 'total_cost', 'order_date', 'expected_date',
        'received_date', 'notes', 'journal_entry_id',
    ];

    protected $casts = [
        'total_cost' => 'decimal:2',
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }

    public function scopeForOwner($query, $ownerId) { return $query->where('owner_id', $ownerId); }

    public static function generatePONumber(int $ownerId): string
    {
        $year = date('Y');
        $count = static::where('owner_id', $ownerId)->whereYear('created_at', $year)->count();
        return 'PO-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }
}
