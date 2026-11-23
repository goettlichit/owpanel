<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Lang;

/**
 * One run of a Hetzner snapshot (E-095): pending, creating, done or failed.
 * The image itself lives at Hetzner; this record says when and how the run
 * went and how many old snapshots it removed.
 */
class ServerSnapshot extends Model
{
    use HasUlids, MassPrunable;

    public const PENDING = 'pending';

    public const CREATING = 'creating';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /**
     * Longest wait for Hetzner to finish a snapshot.
     */
    public const MAX_MINUTES = 180;

    protected $fillable = ['trigger', 'status', 'user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hetzner_image_id' => 'integer',
            'hetzner_action_id' => 'integer',
            'deleted_count' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::DONE, self::FAILED], true);
    }

    public function fail(string $error): void
    {
        $this->forceFill(['status' => self::FAILED, 'error' => substr($error, 0, 100), 'finished_at' => now()])->save();
    }

    public function errorMessage(): ?string
    {
        if ($this->error === null) {
            return null;
        }

        if (str_starts_with($this->error, 'rotation_')) {
            return __('snapshots.errors.rotation');
        }

        return Lang::has('snapshots.errors.'.$this->error) ? __('snapshots.errors.'.$this->error) : $this->error;
    }

    /**
     * Records are kept for 90 days, like tasks (E-093).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::whereIn('status', [self::DONE, self::FAILED])->where('created_at', '<', now()->subDays(90));
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
