<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry in the audit log (E-053). Entries are written through
 * App\Support\Audit and never changed afterwards.
 */
class AuditLog extends Model
{
    use HasUlids;
    use MassPrunable;

    /**
     * Retention (E-092): failed sign-ins after 90 days, everything else
     * after one year. Removed every night by model:prune.
     */
    public const KEEP_DAYS = 365;

    public const KEEP_FAILED_DAYS = 90;

    public const FAILED_ACTIONS = ['auth.failed', 'auth.two_factor_failed', 'auth.lockout'];

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->orWhere(fn (Builder $query) => $query
                ->whereIn('action', self::FAILED_ACTIONS)
                ->where('created_at', '<', now()->subDays(self::KEEP_FAILED_DAYS)));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Colour of the action in the interface (docs/DESIGN.md, "Farben mit
     * Bedeutung"): ok, info, warning, danger or neutral.
     */
    public function tone(): string
    {
        return match ($this->action) {
            'auth.lockout' => 'danger',
            'auth.failed', 'auth.oidc_failed', 'auth.two_factor_failed', 'auth.recovery_code_used', 'account.two_factor_disabled',
            'site.deletion_started', 'site.deleted', 'server.reboot_requested',
            'database.deletion_started', 'database.deleted', 'database.password_revealed', 'database.password_changed',
            'system.queue_paused', 'customer.access_locked', 'customer.access_deleted' => 'warning',
            'auth.login', 'auth.oidc_login', 'account.two_factor_confirmed', 'account.oidc_linked' => 'ok',
            'auth.logout' => 'neutral',
            default => 'info',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
