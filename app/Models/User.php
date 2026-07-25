<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role', 'is_active', 'is_superadmin', 'password_changed_at', 'failed_login_attempts', 'locked_until'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'string',
            'is_active' => 'boolean',
            'is_superadmin' => 'boolean',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_login_attempts' => 'integer',
        ];
    }

    public const MAX_LOGIN_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 30;

    public function mustChangePassword(): bool
    {
        if (is_null($this->password_changed_at)) {
            return true;
        }

        return $this->password_changed_at->diffInDays(now()) >= 3;
    }

    public function superadminPasswordExpired(): bool
    {
        if (!$this->isSuperadmin()) {
            return false;
        }

        if (is_null($this->password_changed_at)) {
            return true;
        }

        return $this->password_changed_at->diffInMonths(now()) >= 6;
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function ownerProfile(): HasOne
    {
        return $this->hasOne(OwnerProfile::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'owner_id');
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isSuperadmin(): bool
    {
        return $this->is_superadmin || $this->role === 'superadmin';
    }

    public function isSupplier(): bool
    {
        return $this->role === 'supplier';
    }

    public function isLocked(): bool
    {
        if (is_null($this->locked_until)) {
            return false;
        }

        return $this->locked_until->isFuture();
    }

    public function recordFailedLogin(): void
    {
        $attempts = $this->failed_login_attempts + 1;

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $this->update([
                'failed_login_attempts' => $attempts,
                'locked_until' => now()->addMinutes(self::LOCKOUT_MINUTES),
            ]);
        } else {
            $this->update(['failed_login_attempts' => $attempts]);
        }
    }

    public function resetFailedLoginAttempts(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    public function unlockAccount(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
    }

    public function getPasswordStatus(): array
    {
        return [
            'password_changed_at' => $this->password_changed_at?->toIso8601String(),
            'must_change_password' => $this->mustChangePassword(),
            'is_password_expired' => $this->isOwner() && $this->mustChangePassword(),
            'days_since_last_change' => $this->password_changed_at
                ? $this->password_changed_at->diffInDays(now())
                : null,
            'is_account_locked' => $this->isLocked(),
            'locked_until' => $this->locked_until?->toIso8601String(),
            'failed_login_attempts' => $this->failed_login_attempts,
        ];
    }
}
