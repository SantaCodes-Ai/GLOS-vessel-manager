<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property bool $is_active
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('email') && $user->getOriginal('registration_status') === 'approved') {
                $user->email_verified_at = null;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get vessels assigned to this user.
     */
    public function vessels(): BelongsToMany
    {
        return $this->belongsToMany(
            Vessel::class,
            'vessel_user_access'
        )->withTimestamps();
    }

    /**
     * Check whether the user is an administrator.
     */
    public function isAdmin(): bool
    {
        // Transitional alias only; all new granted roles use UserRole's two cases.
        return in_array($this->role, [UserRole::Admin->value, 'super_admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        // Legacy inspection helper, never an authorisation boundary.
        return $this->role === 'super_admin';
    }

    public function scopeAdministrators($query)
    {
        return $query->whereIn('role', ['admin', 'super_admin']);
    }

    public function scopeAccessibleAdministrators($query)
    {
        return $query->administrators()->where('is_active', true)->where(fn ($query) => $query
            ->whereNull('registration_status')->orWhere(fn ($query) => $query->where('registration_status', 'approved')->whereNotNull('email_verified_at')));
    }

    public function canAccessApplication(): bool
    {
        return $this->is_active && $this->hasApprovedIdentity();
    }

    public function hasApprovedIdentity(): bool
    {
        return $this->registration_status === null || ($this->registration_status === 'approved' && $this->hasVerifiedEmail());
    }

    public function isManagedClient(): bool
    {
        return $this->isClient() && in_array($this->registration_status, [null, 'approved'], true);
    }

    /**
     * Check whether the user is a client.
     */
    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1)
                .Str::substr($initials, -1)
            : $initials;
    }
}
