<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'user_id', 'type', 'title', 'message', 'link', 'read_at', 'data',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }

    public function scopeForUser($query, $userId) { return $query->where('user_id', $userId); }
    public function scopeUnread($query) { return $query->whereNull('read_at'); }
}
