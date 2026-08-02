<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WingaCommission extends Model
{
    protected $fillable = [
        'winga_id',
        'order_id',
        'order_amount',
        'commission_rate',
        'commission_amount',
        'withholding_tax',
        'net_amount',
        'status',
        'journal_entry_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'order_amount' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function winga(): BelongsTo
    {
        return $this->belongsTo(Winga::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function scopeForOwner(Builder $query, int $ownerId): Builder
    {
        return $query->whereHas('winga', fn ($q) => $q->where('owner_id', $ownerId));
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }
}
