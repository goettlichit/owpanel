<?php

namespace App\Models;

use App\Databases\Transfers;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Support\Audit;
use Database\Factories\DatabaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A MariaDB database of a website with its user of the same name (E-078),
 * created and deleted by db-create and db-delete as server tasks. The
 * password is stored encrypted, so the panel can show it again and later
 * sign in to phpMyAdmin with it.
 *
 * Status values are those of websites: pending, active, failed, deleting.
 */
class Database extends Model
{
    /** @use HasFactory<DatabaseFactory> */
    use HasFactory, HasUlids;

    /**
     * Suffix after "<site user>_": letters, digits and underscores, starting
     * with a letter or digit, at most 16 characters.
     */
    public const SUFFIX_PATTERN = '/^[a-z0-9][a-z0-9_]{0,15}$/';

    public const HOST = 'localhost';

    public const PORT = 3306;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $guarded = ['*'];

    protected $hidden = ['password', 'new_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'password' => 'encrypted',
            'new_password' => 'encrypted',
        ];
    }

    /**
     * Same rule as owp_valid_db_name in server/lib/database.sh.
     */
    public static function isValidName(string $name): bool
    {
        if (! preg_match('/^(ow([1-5][0-9]{4}))_(.+)$/', $name, $match)) {
            return false;
        }

        $number = (int) $match[2];

        return $number >= Site::USER_MIN && $number <= Site::USER_MAX
            && preg_match(self::SUFFIX_PATTERN, $match[3]) === 1;
    }

    /**
     * 40 letters and digits: strong, and safe inside SQL and config files.
     */
    public static function generatePassword(): string
    {
        return Str::password(40, letters: true, numbers: true, symbols: false, spaces: false);
    }

    /**
     * The password db-create should set: a new one while it waits for
     * confirmation, otherwise the current one.
     */
    public function pendingPassword(): ?string
    {
        return $this->new_password ?? $this->password;
    }

    public function isBusy(): bool
    {
        return $this->tasks()
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])
            ->exists();
    }

    /**
     * Applies the outcome of db-create, db-delete, db-export or db-import. Called by the queue
     * worker (App\Jobs\RunServerTask).
     */
    public function taskFinished(ServerTask $task): void
    {
        $succeeded = $task->status === TaskStatus::Succeeded;

        match ($task->script) {
            ServerScript::DbCreate => $succeeded ? $this->confirmCreated() : $this->createFailed(),
            ServerScript::DbDelete => $succeeded
                ? $this->deleteAfterScript($task)
                : $this->forceFill(['status' => SiteStatus::Failed])->save(),
            // An upload is not needed any more; a broken export is not offered (E-082).
            ServerScript::DbImport => Transfers::remove($task->transfer_file),
            ServerScript::DbExport => $succeeded ? null : Transfers::remove($task->transfer_file),
            default => null,
        };
    }

    /**
     * A confirmed new password replaces the old one.
     */
    private function confirmCreated(): void
    {
        $this->forceFill([
            'status' => SiteStatus::Active,
            'password' => $this->new_password ?? $this->password,
            'new_password' => null,
        ])->save();
    }

    /**
     * A failed password change keeps the old password, which still works on
     * the server; a failed creation marks the database as failed.
     */
    private function createFailed(): void
    {
        if ($this->new_password !== null && $this->status === SiteStatus::Active) {
            $this->forceFill(['new_password' => null])->save();

            return;
        }

        $this->forceFill(['status' => SiteStatus::Failed, 'new_password' => null])->save();
    }

    private function deleteAfterScript(ServerTask $task): void
    {
        Audit::record('database.deleted', $this, [
            'database' => $this->name,
            'site' => $this->site_id,
        ], $task->user);

        $this->delete();
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<ServerTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ServerTask::class);
    }
}
