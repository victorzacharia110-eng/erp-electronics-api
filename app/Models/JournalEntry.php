<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $fillable = [
        'owner_id', 'reference', 'date', 'description', 'status',
        'source_type', 'source_id', 'prepared_by', 'posted_by',
        'voided_by', 'posted_at', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function getTotalDebitAttribute(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function getTotalCreditAttribute(): float
    {
        return (float) $this->lines->sum('credit');
    }

    public function isBalanced(): bool
    {
        return abs($this->total_debit - $this->total_credit) < 0.01;
    }

    public function scopeForOwner($query, $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeDrafts($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public static function generateReference(int $ownerId): string
    {
        $year = date('Y');
        $count = static::where('owner_id', $ownerId)
            ->whereYear('date', $year)
            ->count();
        return 'JE-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }
}
