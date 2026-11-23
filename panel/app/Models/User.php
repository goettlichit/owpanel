<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\Theme;
use App\Notifications\ResetPasswordNotification;
use App\Notify\Channel;
use App\Notify\NotificationEvent;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Panel user. Role and customer are not mass assignable: they are set
 * explicitly where users are created, never from a request.
 */
#[Fillable(['first_name', 'last_name', 'email', 'password', 'locale', 'theme'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'invitation_token', 'known_devices'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, TwoFactorAuthenticatable;

    protected $attributes = [
        'theme' => 'system',
    ];

    protected static function booted(): void
    {
        // Administrators never belong to a customer, customers always do.
        static::saving(function (User $user): void {
            if ($user->role === Role::Admin && $user->customer_id !== null) {
                throw new InvalidArgumentException('Administrators must not belong to a customer.');
            }

            if ($user->role === Role::Customer && $user->customer_id === null) {
                throw new InvalidArgumentException('Customer users must belong to a customer.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'theme' => Theme::class,
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'known_devices' => 'array',
            'password_change_required' => 'boolean',
            'notification_prefs' => 'array',
            'quiet_enabled' => 'boolean',
            'oidc_allowed' => 'boolean',
            'locked_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Full name for display, e.g. "Alexander Göttlich".
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * Initials for the avatar, e.g. "AG".
     *
     * @return Attribute<string, never>
     */
    protected function initials(): Attribute
    {
        return Attribute::get(fn () => mb_strtoupper(
            mb_substr((string) $this->first_name, 0, 1).mb_substr((string) $this->last_name, 0, 1)
        ));
    }

    /**
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Links with Pocket ID or another OpenID Connect provider (E-114).
     *
     * @return HasMany<OidcIdentity, $this>
     */
    public function oidcIdentities(): HasMany
    {
        return $this->hasMany(OidcIdentity::class);
    }

    /**
     * Whether the user gets this event on this channel (E-101). Only
     * administrators are notified; without a choice every event is on.
     */
    public function wantsNotification(NotificationEvent $event, Channel $channel): bool
    {
        return $this->isAdmin() && (bool) ($this->notification_prefs[$event->value][$channel->value] ?? true);
    }

    /**
     * Whether the quiet hours apply at this moment, in the panel's time
     * zone. From 22:00 until 07:00 spans midnight.
     */
    public function inQuietHours(?CarbonInterface $at = null): bool
    {
        if (! $this->quiet_enabled || $this->quiet_from === $this->quiet_until) {
            return false;
        }
        $time = ($at ?? now())->copy()->setTimezone((string) config('owpanel.timezone'))->format('H:i');

        return $this->quiet_from < $this->quiet_until
            ? $time >= $this->quiet_from && $time < $this->quiet_until
            : $time >= $this->quiet_from || $time < $this->quiet_until;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Pocket ID: always for administrators, for customers only after an
     * administrator turned it on (E-114).
     */
    public function mayUseOidc(): bool
    {
        return $this->isAdmin() || $this->oidc_allowed;
    }

    /**
     * A locked account cannot log in, with password or Pocket ID (E-127).
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * An invitation link was sent and is neither used nor expired (E-128).
     */
    public function hasOpenInvitation(): bool
    {
        return $this->invitation_token !== null && $this->invitation_expires_at?->isFuture() === true;
    }

    public function isCustomer(): bool
    {
        return $this->role === Role::Customer;
    }

    /**
     * Two-factor authentication counts only once the code was confirmed.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Reset link in the account's language through the queue (E-094).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify((new ResetPasswordNotification($token))->locale($this->locale ?? config('app.locale')));
    }
}
