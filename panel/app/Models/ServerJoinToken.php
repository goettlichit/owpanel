<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A one-time code for "Add server" (E-166, E-196): valid 15 minutes, used
 * once. Only its SHA-256 is stored; the code is shown once.
 *
 * @property string $id
 * @property string $token_hash
 * @property string $name
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $server_id
 * @property string|null $user_id
 */
class ServerJoinToken extends Model
{
    use HasUlids;

    public const MINUTES = 15;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'backup' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * Creates a code for a server of that name; returns the code once.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, ?User $user, bool $backup = false): array
    {
        $code = Str::random(48);
        $token = new self;
        $token->forceFill([
            'token_hash' => hash('sha256', $code),
            'name' => $name,
            'backup' => $backup,
            'expires_at' => now()->addMinutes(self::MINUTES),
            'user_id' => $user?->getKey(),
        ])->save();

        return [$token, $code];
    }

    /**
     * The open token for a code, or null if it is wrong, used or expired.
     */
    public static function findOpen(?string $code): ?self
    {
        if (! is_string($code) || preg_match('/^[A-Za-z0-9_-]{32,128}$/', $code) !== 1) {
            return null;
        }

        return self::where('token_hash', hash('sha256', $code))
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
