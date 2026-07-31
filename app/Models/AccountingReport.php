<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingReport extends Model
{
    protected $fillable = [
        'owner_id',
        'report_type',
        'period_label',
        'period_start',
        'period_end',
        'data',
        'summary',
        'suggestions',
        'suggestions_source',
        'suggestions_generated_at',
        'is_finalized',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'data' => 'array',
            'summary' => 'array',
            'suggestions' => 'array',
            'suggestions_generated_at' => 'datetime',
            'is_finalized' => 'boolean',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
