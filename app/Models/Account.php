<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Account extends Model
{
    protected $fillable = [
        'owner_id', 'code', 'name', 'type', 'normal_balance',
        'parent_id', 'description', 'is_active', 'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function journalEntries(): HasManyThrough
    {
        return $this->hasManyThrough(JournalEntry::class, JournalLine::class, 'account_id', 'id', 'id', 'journal_entry_id');
    }

    public function getBalanceAttribute(): float
    {
        $posted = $this->journalLines()
            ->whereHas('journalEntry', fn($q) => $q->where('status', 'posted'))
            ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debit = (float) ($posted->total_debit ?? 0);
        $credit = (float) ($posted->total_credit ?? 0);

        if ($this->normal_balance === 'debit') {
            return $debit - $credit;
        }
        return $credit - $debit;
    }

    public function getFormattedCodeAttribute(): string
    {
        return str_pad($this->code, 4, '0', STR_PAD_LEFT);
    }

    public function scopeForOwner($query, $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}
