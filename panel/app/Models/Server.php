<?php

namespace App\Models;

use App\Enums\TaskStatus;
use App\Servers\HostKey;
use App\Servers\ScriptCall;
use App\Support\IpNetwork;
use App\Support\PanelRelease;
use Carbon\CarbonInterface;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Lang;

/**
 * A managed server, reached over SSH as owpanel-agent (E-051).
 */
#[Fillable(['name', 'host', 'port', 'public_ipv4', 'public_ipv6', 'host_key'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasUlids;

    /**
     * A server counts as online if it answered within this many minutes.
     */
    public const ONLINE_MINUTES = 3;

    protected $hidden = ['backup_repo_password'];

    /**
     * @return array<string, string>
     */
    /**
     * Defaults of the backup settings, as in the migration (E-100), so a
     * new server has them before it is read back.
     *
     * @var array<string, mixed>
     */
    /** Hours between scheduled backups that can be chosen (E-103). */
    public const BACKUP_INTERVALS = [24, 12, 6, 3, 1];

    protected $attributes = [
        'backup_path' => 'owpanel',
        'backup_external' => false,
        'backup_time' => '02:30',
        'backup_interval' => 24,
        'backup_keep_hourly' => 24,
        'backup_keep_daily' => 7,
        'backup_keep_weekly' => 4,
        'backup_keep_monthly' => 6,
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'cpu_cores' => 'integer',
            'booted_at' => 'datetime',
            'reboot_required' => 'boolean',
            // User quotas for /var/www (E-126); null before the first measurement.
            'quota_active' => 'boolean',
            // Website users the status check found on the server (E-106).
            'present_sites' => 'array',
            'checked_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'updates' => 'array',
            'updates_checked_at' => 'datetime',
            'certificate_renewal' => 'boolean',
            'panel_certificate_expires_at' => 'datetime',
            'hetzner_server_id' => 'integer',
            'snapshot_enabled' => 'boolean',
            'snapshot_keep_daily' => 'integer',
            'snapshot_keep_weekly' => 'integer',
            // Repository password of the restic backups (E-096).
            'backup_repo_password' => 'encrypted',
            'backup_ready' => 'boolean',
            'backup_box_id' => 'integer',
            'services' => 'array',
            'release_keys' => 'array',
            'php_versions' => 'array',
            'backup_subaccount_id' => 'integer',
            'backup_external' => 'boolean',
            'backup_interval' => 'integer',
            'backup_keep_hourly' => 'integer',
            'backup_keep_daily' => 'integer',
            'backup_keep_weekly' => 'integer',
            'backup_keep_monthly' => 'integer',
        ];
    }

    /**
     * Host key is always stored normalized, e.g. without the comment.
     *
     * @return Attribute<string, string>
     */
    protected function hostKey(): Attribute
    {
        return Attribute::set(fn (string $value) => HostKey::normalize($value));
    }

    /**
     * "SHA256:…", the same as `ssh-keygen -lf` shows on the server.
     *
     * @return Attribute<string, never>
     */
    protected function hostKeyFingerprint(): Attribute
    {
        return Attribute::get(fn () => HostKey::fingerprint($this->host_key));
    }

    /**
     * "host" or "host:port" if the port is not 22. IPv6 addresses get brackets.
     *
     * @return Attribute<string, never>
     */
    protected function address(): Attribute
    {
        return Attribute::get(function () {
            $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

            return $this->port === 22 ? $host : $host.':'.$this->port;
        });
    }

    /**
     * "online", "offline" or "unknown" (E-075). Online means the last check
     * succeeded and is recent; the check runs once a minute, so three
     * minutes leave room for a slow run.
     */
    public function onlineState(): string
    {
        if ($this->checked_at === null) {
            return 'unknown';
        }

        $recent = $this->last_seen_at !== null
            && $this->last_seen_at->greaterThanOrEqualTo(now()->subMinutes(self::ONLINE_MINUTES));

        return $this->check_error === null && $recent ? 'online' : 'offline';
    }

    /**
     * Update state for the hints (E-076): "reboot" if the server must be
     * restarted, "security" or "pending" if updates wait, "current", or
     * "unknown" before the first check.
     */
    public function updateState(): string
    {
        if ($this->rebootRequired()) {
            return 'reboot';
        }

        if ($this->updates === null) {
            return 'unknown';
        }

        return match (true) {
            $this->updates['security'] > 0 => 'security',
            $this->updates['pending'] > 0 => 'pending',
            default => 'current',
        };
    }

    /**
     * Whether the server must be restarted. The minutely status check and the
     * hourly update check test the same thing; the newer one decides, so the
     * hint disappears within a minute after a restart.
     */
    public function rebootRequired(): bool
    {
        $fromStatus = (bool) $this->reboot_required;
        $fromUpdates = (bool) ($this->updates['reboot']['required'] ?? false);

        // Both from the same run, or no usable status: either one counts.
        if ($this->checked_at === null || $this->check_error !== null || $this->updates_checked_at === null
            || $this->checked_at->diffInSeconds($this->updates_checked_at, true) < 120) {
            return $fromStatus || $fromUpdates;
        }

        return $this->checked_at->greaterThan($this->updates_checked_at) ? $fromStatus : $fromUpdates;
    }

    /**
     * Whether the panel itself runs on this server; the installer adds it
     * with the address 127.0.0.1.
     */
    public function hostsPanel(): bool
    {
        return in_array($this->host, ['127.0.0.1', '::1', 'localhost'], true);
    }

    /**
     * Whether the server scripts differ from the panel's version (E-167).
     * Unknown (null) counts as current: older scripts and checkouts do not
     * report a version.
     */
    public function scriptsOutdated(): bool
    {
        return $this->scripts_version !== null && $this->scripts_version !== PanelRelease::installed();
    }

    /**
     * The server the panel runs on, if it is in the list (E-099).
     */
    public static function panelHost(): ?self
    {
        return self::query()->whereIn('host', ['127.0.0.1', '::1', 'localhost'])->orderBy('created_at')->first();
    }

    /**
     * Whether a Storage Box is chosen for this server's backups (E-100).
     */
    public function backupConfigured(): bool
    {
        return $this->backup_box_id !== null;
    }

    /**
     * Backups run: a box is chosen and the server is connected to it.
     */
    public function backupActive(): bool
    {
        return $this->backupConfigured() && (bool) $this->backup_ready;
    }

    /**
     * Retention of the scheduled backups of this server.
     *
     * @return array{hourly: int, daily: int, weekly: int, monthly: int}
     */
    /**
     * The start of the backup slot that is due now, in UTC, or null before
     * today's backup time when backups run daily (E-103). Slots repeat
     * every backup_interval hours from backup_time on, across midnight.
     */
    public function backupSlot(?CarbonInterface $now = null): ?CarbonInterface
    {
        $local = ($now ?? now())->copy()->setTimezone((string) config('owpanel.timezone'));
        $start = $local->copy()->setTimeFromTimeString((string) $this->backup_time);
        $interval = in_array((int) $this->backup_interval, self::BACKUP_INTERVALS, true) ? (int) $this->backup_interval : 24;

        if ($interval === 24) {
            return $local->lessThan($start) ? null : $start->utc();
        }
        if ($local->lessThan($start)) {
            $start->subDay();
        }
        $steps = intdiv((int) $start->diffInMinutes($local, true), $interval * 60);

        return $start->addHours($steps * $interval)->utc();
    }

    public function backupRetention(): array
    {
        return ['hourly' => $this->backup_interval < 24 ? (int) $this->backup_keep_hourly : 0, 'daily' => (int) $this->backup_keep_daily, 'weekly' => (int) $this->backup_keep_weekly, 'monthly' => (int) $this->backup_keep_monthly];
    }

    public function pendingUpdates(): int
    {
        return (int) ($this->updates['pending'] ?? 0);
    }

    public function securityUpdates(): int
    {
        return (int) ($this->updates['security'] ?? 0);
    }

    /**
     * Readable reason why the last check failed.
     */
    public function checkErrorMessage(): ?string
    {
        if ($this->check_error === null) {
            return null;
        }

        return Lang::has('tasks.errors.'.$this->check_error)
            ? __('tasks.errors.'.$this->check_error)
            : __('servers.state.error_unknown', ['code' => $this->check_error]);
    }

    /**
     * Full host name reported by the server, or the panel's name for it.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->hostname ?: $this->name);
    }

    /**
     * @return HasMany<ServerMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    /**
     * @return HasOne<ServerMetric, $this>
     */
    public function latestMetric(): HasOne
    {
        return $this->hasOne(ServerMetric::class)->latestOfMany('recorded_at');
    }

    /**
     * @return HasMany<ServerTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ServerTask::class);
    }

    /**
     * The result of backup-setup decides whether websites of this server
     * are backed up (E-096). The public key is shown so it can be found on
     * the Storage Box. When the panel's own server gets connected, the
     * panel backs itself up right away instead of waiting for the night
     * (E-099); this run counts as that day's scheduled one.
     */
    public function backupSetupFinished(ServerTask $task): void
    {
        $ok = $task->status === TaskStatus::Succeeded;
        $wasReady = (bool) $this->backup_ready;
        $key = $task->result['public_key'] ?? null;
        $this->forceFill([
            'backup_ready' => $ok,
            'backup_public_key' => $ok && is_string($key) && preg_match('#^ssh-ed25519 [A-Za-z0-9+/=]+$#', $key) ? $key : $this->backup_public_key,
        ])->save();

        if ($ok && ! $wasReady && $this->hostsPanel()) {
            ServerTask::start($this, ScriptCall::panelBackup(false, $this->backupRetention()), $task->user);
        }
    }

    /**
     * @return HasMany<ServerSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ServerSnapshot::class);
    }

    /**
     * @return HasMany<FirewallRule, $this>
     */
    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }

    /**
     * @return HasMany<Fail2banIgnore, $this>
     */
    public function fail2banIgnores(): HasMany
    {
        return $this->hasMany(Fail2banIgnore::class);
    }

    /**
     * The whole list of own firewall rules for firewall-rules (E-090).
     */
    public function firewallCall(): ScriptCall
    {
        return ScriptCall::firewallRules(
            $this->firewallRules()->orderBy('created_at')->orderBy('id')->get()
                ->flatMap(fn (FirewallRule $rule): array => $rule->lines())->values()->all(),
        );
    }

    /**
     * The whole list of fail2ban exceptions (E-090).
     */
    public function fail2banIgnoreCall(): ScriptCall
    {
        return ScriptCall::fail2banIgnore(
            $this->fail2banIgnores()->orderBy('created_at')->orderBy('id')->pluck('address')->all(),
        );
    }

    /**
     * @return HasMany<Blocklist, $this>
     */
    public function blocklists(): HasMany
    {
        return $this->hasMany(Blocklist::class);
    }

    /**
     * All blocklists of the server with their exceptions (E-170): the
     * fail2ban exceptions and the addresses of the panel server, which the
     * lists may never cut off.
     */
    public function blocklistCall(): ScriptCall
    {
        $block = [];
        foreach ($this->blocklists()->orderBy('created_at')->orderBy('id')->get() as $list) {
            foreach ($list->cachedEntries() as $entry) {
                $block[$entry] = true;
            }
        }
        $block = array_slice(array_keys($block), 0, Blocklist::MAX_ENTRIES_PER_SERVER);

        $allow = $this->fail2banIgnores()->pluck('address')->all();
        $panel = self::panelHost();
        foreach ([$panel?->public_ipv4, $panel?->public_ipv6] as $address) {
            if (is_string($address) && ($normalized = IpNetwork::normalize($address)) !== null) {
                $allow[] = $normalized;
            }
        }
        $allow = array_values(array_unique(array_map(fn (string $address) => IpNetwork::withPrefix($address), $allow)));

        return ScriptCall::serverBlocklist($block, $allow);
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * PHP versions websites can use on this server (E-172). Before the
     * server has reported them, only the default, which every server has.
     *
     * @return list<string>
     */
    public function phpVersions(): array
    {
        $versions = is_array($this->php_versions) ? array_values(array_intersect(config('owpanel.php_versions'), $this->php_versions)) : [];

        return $versions !== [] ? $versions : [config('owpanel.php_default')];
    }

    /**
     * The version a new website gets on this server.
     */
    public function defaultPhpVersion(): string
    {
        $versions = $this->phpVersions();

        return in_array(config('owpanel.php_default'), $versions, true) ? config('owpanel.php_default') : $versions[0];
    }

    /**
     * The version to ask for when a website is set up here again: its own if
     * this server has it, else none, so site-create keeps or picks one.
     */
    public function phpFor(Site $site): ?string
    {
        return in_array($site->php_version, $this->phpVersions(), true) ? $site->php_version : null;
    }
}
