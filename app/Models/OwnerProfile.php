<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'is_active',
        'subscription_status',
        'subscription_expires_at',
        'subscription_plan',
        'deactivation_reason',
        'max_products',
        'max_employees',
        'brand_store_name',
        'brand_tagline',
        'brand_logo_path',
        'brand_color',
        'brand_color_secondary',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'subscription_expires_at' => 'datetime',
        'max_products' => 'integer',
        'max_employees' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSubscriptionActive(): bool
    {
        return $this->subscription_status === 'active'
            && $this->subscription_expires_at
            && $this->subscription_expires_at->isFuture();
    }

    public function isTrialActive(): bool
    {
        return $this->subscription_status === 'trial'
            && $this->subscription_expires_at
            && $this->subscription_expires_at->isFuture();
    }

    public function productCount(): int
    {
        return \App\Models\Product::where('owner_id', $this->user_id)->count();
    }

    public function employeeCount(): int
    {
        return User::where('role', 'employee')
            ->whereHas('employeeProfile.branch', function ($q) {
                $q->where('owner_id', $this->user_id);
            })
            ->count();
    }
}
