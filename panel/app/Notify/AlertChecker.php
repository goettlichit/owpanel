<?php

namespace App\Notify;

use App\Backups\StorageBoxUsage;
use App\Customers\StorageQuota;
use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Alert;
use App\Models\Blocklist;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\ServerTask;
use App\Models\Site;
use App\Support\Heartbeat;
use App\Support\PanelRelease;
use App\Support\Units;
use Illuminate\Support\Carbon;

/**
 * Compares the state of servers and websites with the open alerts, once a
 * minute (E-101). It reads only what the panel already knows from its
 * checks, tasks and snapshot runs; it never contacts a server itself.
 */
final class AlertChecker
{
    /** Disk usage from which an alert is sent, and below which it ends. */
    public const DISK_ALERT_PERCENT = 90;

    public const DISK_CLEAR_PERCENT = 85;

    /**
     * Updates that wait this long are reported (E-159). Unattended upgrades
     * install security updates at night; what is still there a day later
     * needs someone.
     */
    public const UPDATES_WAIT_HOURS = 24;

    /**
     * Failed fetches of a blocklist in a row before it is reported (E-170);
     * a single failure stays quiet, the last good entries still apply.
     */
    public const BLOCKLIST_FAILURES = 2;

    /** Scheduled backups older than this are no longer looked at. */
    private const BACKUP_DAYS = 3;

    /** @var list<string> */
    private array $seen = [];

    public function run(): void
    {
        Server::with('latestMetric')->each(fn (Server $server) => $this->checkServer($server));
        Site::with('server')->where('tls', true)->each(fn (Site $site) => $this->checkCertificate($site));
        Customer::with(['package', 'sites'])->whereHas('package', fn ($query) => $query->whereNotNull('storage_mb'))
            ->each(fn (Customer $customer) => $this->checkStorage($customer));

        $this->checkPanelRelease();
        $this->checkStorageBoxes();
        $this->checkHeartbeat();
        Blocklist::with('server')->each(fn (Blocklist $list) => $this->checkBlocklist($list));

        // Servers and websites that were deleted, and states that end
        // without a message of their own (updates installed, panel
        // updated): their alerts end silently.
        Alert::whereNull('resolved_at')->whereNotIn('key', $this->seen)->update(['resolved_at' => now()]);
    }

    private function checkServer(Server $server): void
    {
        $id = $server->getKey();
        $params = ['server' => $server->name];

        $state = $server->onlineState();
        $this->apply(
            NotificationEvent::ServerOffline, "server_offline:{$id}",
            $state === 'unknown' ? null : $state === 'offline',
            $params, route('servers.show', $server),
        );

        // State values from an offline server are old; they wait.
        $online = $state === 'online';
        $this->apply(
            NotificationEvent::RebootRequired, "reboot_required:{$id}",
            $online ? $server->rebootRequired() : null,
            $params, route('servers.show', $server),
        );

        $this->checkUpdates($server, $online);
        $this->checkPanelCertificate($server);
        $this->checkServices($server, $online);

        $metric = $server->latestMetric;
        $disk = $online && $metric !== null && $metric->disk_total > 0 ? $metric->diskPercent() : null;
        $diskKey = "disk_full:{$id}";
        $this->apply(
            NotificationEvent::DiskFull, $diskKey,
            match (true) {
                $disk === null => null,
                $disk >= self::DISK_ALERT_PERCENT => true,
                $disk < self::DISK_CLEAR_PERCENT => false,
                default => null,
            },
            $params + ['percent' => (int) round((float) $disk)], route('servers.show', $server),
        );

        $snapshot = $server->snapshots()->whereIn('status', [ServerSnapshot::DONE, ServerSnapshot::FAILED])->latest()->first();
        $this->apply(
            NotificationEvent::SnapshotFailed, "snapshot_failed:{$id}",
            $snapshot === null ? null : $snapshot->status === ServerSnapshot::FAILED,
            $params, route('servers.snapshots', $server),
        );

        $failed = $this->failedBackups($server);
        $this->apply(
            NotificationEvent::BackupFailed, "backup_failed:{$id}",
            $failed,
            $params + ['items' => implode(', ', (array) $failed)], route('servers.backups', $server),
        );
    }

    /**
     * Updates waiting for more than a day, and automatic updates that are
     * off (E-159). Installed updates have their own message, so the first
     * alert ends without one.
     */
    private function checkUpdates(Server $server, bool $online): void
    {
        $id = $server->getKey();
        $updates = $server->updates;
        if (! $online || ! is_array($updates)) {
            // Unknown: keep both as they are.
            $this->seen[] = "updates_available:{$id}";
            $this->seen[] = "auto_updates_off:{$id}";

            return;
        }

        $since = is_string($updates['pending_since'] ?? null) ? Carbon::parse($updates['pending_since']) : null;
        if (($updates['pending'] ?? 0) > 0) {
            $this->apply(
                NotificationEvent::UpdatesAvailable, "updates_available:{$id}",
                $since !== null && $since->lessThanOrEqualTo(now()->subHours(self::UPDATES_WAIT_HOURS)) ? true : null,
                [
                    'server' => $server->name,
                    'count' => (int) $updates['pending'],
                    'security' => (int) ($updates['security'] ?? 0),
                    'since' => $since?->copy()->setTimezone((string) config('owpanel.timezone'))->isoFormat('L') ?? '',
                ],
                route('servers.updates', $server),
            );
        }

        if (is_bool($updates['automatic'] ?? null)) {
            $this->apply(
                NotificationEvent::AutoUpdatesOff, "auto_updates_off:{$id}",
                ! $updates['automatic'],
                ['server' => $server->name],
                route('servers.updates', $server),
            );
        } else {
            $this->seen[] = "auto_updates_off:{$id}";
        }
    }

    /**
     * Services the server reports as stopped (E-160): nginx, PHP-FPM,
     * MariaDB, fail2ban, nftables. A service that is no longer enabled
     * drops out of the list and its alert ends silently.
     */
    private function checkServices(Server $server, bool $online): void
    {
        foreach ((array) $server->services as $name => $active) {
            $this->apply(
                NotificationEvent::ServiceDown, "service_down:{$server->getKey()}:{$name}",
                $online ? ! $active : null,
                ['server' => $server->name, 'service' => (string) $name],
                route('servers.show', $server),
            );
        }
    }

    /**
     * The certificate of the panel itself (E-159). Without it nobody
     * reaches the panel, so it warns like a website's certificate.
     */
    private function checkPanelCertificate(Server $server): void
    {
        $expires = $server->panel_certificate_expires_at;
        if ($expires === null) {
            return;
        }
        $this->apply(
            NotificationEvent::CertificateExpiring, "certificate_expiring:panel:{$server->getKey()}",
            $expires->lessThan(now()->addDays(Site::CERTIFICATE_WARNING_DAYS)),
            [
                'site' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
                'server' => $server->name,
                'date' => $expires->copy()->setTimezone((string) config('owpanel.timezone'))->isoFormat('L'),
            ],
            route('servers.show', $server),
        );
    }

    /**
     * Storage Boxes of the backups (E-160), with the thresholds of the
     * server disks. Boxes no longer in use end silently.
     */
    private function checkStorageBoxes(): void
    {
        foreach (StorageBoxUsage::all() as $id => $box) {
            $percent = StorageBoxUsage::percent($box);
            $this->apply(
                NotificationEvent::StorageBoxFull, "storage_box_full:{$id}",
                match (true) {
                    $percent >= self::DISK_ALERT_PERCENT => true,
                    $percent < self::DISK_CLEAR_PERCENT => false,
                    default => null,
                },
                ['box' => $box['name'], 'percent' => (int) round($percent)],
                route('settings.backup'),
            );
        }
    }

    /**
     * A blocklist that cannot be fetched (E-170), after a few failures in a
     * row; the all-clear follows with the next successful fetch.
     */
    private function checkBlocklist(Blocklist $list): void
    {
        $this->apply(
            NotificationEvent::BlocklistFailed, "blocklist_failed:{$list->getKey()}",
            match (true) {
                $list->failures >= self::BLOCKLIST_FAILURES => true,
                $list->failures === 0 => false,
                default => null,
            },
            [
                'server' => $list->server->name,
                'list' => $list->label(),
                'error' => $list->errorText(),
            ],
            route('servers.firewall', $list->server),
        );
    }

    /**
     * The heartbeat to the outside monitoring (E-160): reported once it
     * has failed for 15 minutes, so a single lost call stays quiet.
     */
    private function checkHeartbeat(): void
    {
        $state = Heartbeat::url() !== null ? Heartbeat::state() : null;
        if ($state === null) {
            return;
        }
        $since = is_string($state['failing_since'] ?? null) ? Carbon::parse($state['failing_since']) : null;
        $this->apply(
            NotificationEvent::HeartbeatFailed, 'heartbeat_failed',
            $state['ok'] ? false : ($since !== null && $since->lessThanOrEqualTo(now()->subMinutes(15)) ? true : null),
            ['error' => (string) ($state['error'] ?? '')],
            route('settings.services'),
        );
    }

    /**
     * A newer release of the panel (E-159), once per version. The alert
     * ends silently once it is installed or a newer one appears.
     */
    private function checkPanelRelease(): void
    {
        if (! PanelRelease::updateAvailable()) {
            return;
        }
        $version = (string) PanelRelease::latest()['version'];
        $panel = Server::panelHost();
        $this->apply(
            NotificationEvent::PanelUpdateAvailable, 'panel_update_available:'.$version,
            true,
            ['version' => $version, 'installed' => PanelRelease::installed()],
            $panel !== null ? route('servers.updates', $panel) : route('dashboard'),
        );
    }

    /**
     * Names of the websites (and "Panel") whose latest backup failed, not
     * counting failed backups by hand; false if all latest ones succeeded,
     * null if there are none.
     *
     * @return list<string>|false|null
     */
    private function failedBackups(Server $server): array|false|null
    {
        $latest = $server->tasks()
            ->with('site')
            ->whereIn('script', [ServerScript::SiteBackup->value, ServerScript::PanelBackup->value])
            ->whereIn('status', [TaskStatus::Succeeded->value, TaskStatus::Failed->value])
            ->where('created_at', '>=', now()->subDays(self::BACKUP_DAYS))
            ->orderByDesc('created_at')
            ->get()
            // A failed backup by hand is no alarm, a successful one gives the
            // all-clear like a scheduled one.
            ->reject(fn (ServerTask $task) => $task->status === TaskStatus::Failed && in_array('--manual', (array) $task->arguments, true))
            ->unique(fn (ServerTask $task) => $task->script->value.'|'.$task->site_id);

        if ($latest->isEmpty()) {
            return null;
        }

        $failed = $latest->filter(fn (ServerTask $task) => $task->status === TaskStatus::Failed)
            ->map(fn (ServerTask $task) => $task->site?->domain ?? __('notifications.panel'))
            ->sort()->values()->all();

        return $failed === [] ? false : $failed;
    }

    private function checkCertificate(Site $site): void
    {
        $state = $site->certificateState();
        $this->apply(
            NotificationEvent::CertificateExpiring, "certificate_expiring:{$site->getKey()}",
            match ($state) {
                'expiring', 'expired' => true,
                'valid' => false,
                default => null,
            },
            [
                'site' => $site->domain,
                'server' => (string) $site->server?->name,
                'date' => $site->certificate_expires_at?->copy()->setTimezone((string) config('owpanel.timezone'))->isoFormat('L') ?? '',
            ],
            route('sites.show', $site),
        );
    }

    /**
     * A customer has used up the storage of his package (E-126). Before
     * the first measurement nothing is known.
     */
    private function checkStorage(Customer $customer): void
    {
        $used = StorageQuota::usedBytes($customer);
        $limit = (int) StorageQuota::limitBytes($customer);
        $this->apply(
            NotificationEvent::StorageFull, "storage_full:{$customer->getKey()}",
            $used === null ? null : $used >= $limit,
            ['customer' => $customer->display_name, 'used' => Units::bytes((int) $used), 'limit' => Units::bytes($limit)],
            route('customers.package.show', $customer),
        );
    }

    /**
     * @param  bool|list<string>|null  $problem  true or a non-empty list: raise; false: resolve; null: unknown, keep as is
     * @param  array<string, string|int>  $params
     */
    private function apply(NotificationEvent $event, string $key, bool|array|null $problem, array $params, string $url): void
    {
        $this->seen[] = $key;

        if ($problem === null) {
            return;
        }

        if ($problem === false) {
            Notifier::resolve($key);

            return;
        }

        Notifier::raise($event, $key, $params, $url);
    }
}
