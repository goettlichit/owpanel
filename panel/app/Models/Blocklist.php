<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A blocklist of a server's firewall (E-170): an HTTPS address the panel
 * fetches every few hours. The checked entries are kept in the table
 * blocklist_contents; the server gets all lists together through server-blocklist.
 *
 * @property ?string $name
 * @property string $url
 * @property int $interval_hours
 * @property int $entries
 * @property int $ignored
 * @property ?string $content_hash
 * @property ?Carbon $checked_at
 * @property ?Carbon $changed_at
 * @property ?string $error
 * @property ?string $error_detail
 * @property int $failures
 */
class Blocklist extends Model
{
    use HasUlids;

    /** Update intervals the panel offers, in hours. */
    public const INTERVALS = [1, 6, 24];

    public const MAX_PER_SERVER = 10;

    /** Same limit as server-blocklist (OWP_BL_MAX_BLOCK). */
    public const MAX_ENTRIES_PER_SERVER = 250000;

    protected $fillable = ['name', 'url', 'interval_hours'];

    protected function casts(): array
    {
        return [
            'interval_hours' => 'integer',
            'entries' => 'integer',
            'ignored' => 'integer',
            'failures' => 'integer',
            'checked_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $list) => DB::table('blocklist_contents')->where('blocklist_id', $list->getKey())->delete());
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * The cached entries, networks with prefix.
     *
     * @return list<string>
     */
    public function cachedEntries(): array
    {
        $text = DB::table('blocklist_contents')->where('blocklist_id', $this->getKey())->value('entries');
        if (! is_string($text) || $text === '') {
            return [];
        }

        return explode("\n", rtrim($text, "\n"));
    }

    /**
     * @param  list<string>  $entries
     */
    public function cache(array $entries): void
    {
        DB::table('blocklist_contents')->upsert(
            ['blocklist_id' => $this->getKey(), 'entries' => $entries === [] ? '' : implode("\n", $entries)."\n"],
            ['blocklist_id'],
            ['entries'],
        );
    }

    /**
     * Whether the list should be fetched again; a few minutes early, so an
     * hourly list is not skipped because the scheduler ran a little sooner.
     */
    public function due(): bool
    {
        return $this->checked_at === null || $this->checked_at->lte(now()->subHours($this->interval_hours)->addMinutes(5));
    }

    /**
     * Why the last fetch failed, in the reader's language, with the curl
     * error or HTTP status where there is one.
     */
    public function errorText(): string
    {
        if ($this->error === null) {
            return '';
        }

        return self::describeError($this->error, $this->error_detail);
    }

    public static function describeError(string $error, ?string $detail): string
    {
        $text = __('firewall.blocklists.errors.'.$error);

        return $detail !== null && $detail !== '' ? rtrim($text, '.').' ('.$detail.').' : $text;
    }

    /**
     * The name the administrator gave the list, or its address.
     */
    public function label(): string
    {
        return $this->name ?? $this->shortUrl();
    }

    /**
     * Address without scheme, for the table.
     */
    public function shortUrl(): string
    {
        $parts = parse_url($this->url);

        return ($parts['host'] ?? $this->url).($parts['path'] ?? '');
    }
}
