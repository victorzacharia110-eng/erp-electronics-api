<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = [
        'owner_id', 'employee_id', 'order_id', 'order_amount',
        'cost_amount', 'profit_amount',
        'commission_rate', 'commission_amount', 'status',
        'journal_entry_id', 'paid_at',
    ];

    protected $casts = [
        'order_amount' => 'decimal:2',
        'cost_amount' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(User::class, 'employee_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }

    public function scopeForOwner($query, $ownerId) { return $query->where('owner_id', $ownerId); }
    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopePaid($query) { return $query->where('status', 'paid'); }
    public function scopeForEmployee($query, $employeeId) { return $query->where('employee_id', $employeeId); }
}
