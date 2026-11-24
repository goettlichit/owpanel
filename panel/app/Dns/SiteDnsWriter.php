<?php

namespace App\Dns;

use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Models\Site;
use App\Models\SiteDnsRecord;
use App\Models\User;
use App\Support\Audit;
use App\Support\DnsResolver;

/**
 * Creates or corrects the A and AAAA records of a website in its Hetzner
 * zone (E-116): sets each chosen RRSet to exactly the server address,
 * remembers what the panel set and writes every change to the audit log.
 */
class SiteDnsWriter
{
    public const TTLS = [300, 900, 3600, 86400];

    public const DEFAULT_TTL = 3600;

    /**
     * How long one request waits for Hetzner in total; nginx gives up on
     * the panel after 60 seconds. Tests make it shorter.
     */
    public static float $waitSeconds = 40;

    public function __construct(private readonly SiteDns $dns) {}

    /**
     * Records that differ from the server address in the zone, so the panel
     * can set them: missing ones and those pointing elsewhere. Empty when
     * the zone is not reachable through the API.
     *
     * @param  array<string, mixed>|null  $check  result of SiteDns::check
     * @return list<array{key: string, host: string, name: string, type: string, expected: string, current: list<string>, ttl: int|null}>
     */
    public static function changes(?array $check): array
    {
        if ($check === null || $check['zone'] === null || $check['zone_error'] !== null || ($check['zone']['mode'] ?? 'primary') !== 'primary') {
            return [];
        }
        $changes = [];
        foreach ($check['rows'] as $row) {
            if ($row['zone'] === null || $row['zone'] === [$row['expected']]) {
                continue;
            }
            $changes[] = [
                'key' => $row['host'].'|'.$row['type'],
                'host' => $row['host'],
                'name' => $row['name'],
                'type' => $row['type'],
                'expected' => $row['expected'],
                'current' => $row['zone'],
                'ttl' => $row['ttl'],
            ];
        }

        return $changes;
    }

    /**
     * Applies the chosen changes, read fresh from the zone. Stops at the
     * first error; changes made before it stay and are logged.
     *
     * @param  list<string>  $keys  "host|type" of the records to set
     * @return array{done: int, error: string|null, pending: bool}
     */
    public function apply(Site $site, array $keys, int $ttl, User $user): array
    {
        return $this->applyTo($site, $this->dns->check($site, fresh: true), $keys, $ttl, $user);
    }

    /**
     * For a new website (E-116): creates the records that do not exist in
     * the zone yet. Records that point elsewhere stay untouched; the DNS
     * page shows them as different. Null when the zone is not reachable
     * through the API.
     *
     * @return array{done: int, different: int, error: string|null, pending: bool}|null
     */
    public function createMissing(Site $site, User $user): ?array
    {
        $check = $this->dns->check($site, fresh: true);
        if ($check['zone'] === null || $check['zone_error'] !== null) {
            return null;
        }
        $changes = self::changes($check);
        $missing = array_values(array_filter($changes, fn (array $change) => $change['current'] === [] && $change['ttl'] === null));
        $result = $this->applyTo($site, $check, array_column($missing, 'key'), self::DEFAULT_TTL, $user);

        return [...$result, 'different' => count($changes) - count($missing)];
    }

    /**
     * The panel's records of a website with what the zone holds now:
     * removable only while the record holds exactly the value the panel
     * set; anything else was changed by someone and stays.
     *
     * @return list<array{record: SiteDnsRecord, host: string, current: list<string>|null, removable: bool}>
     */
    public function owned(Site $site): array
    {
        $cloud = HetznerCloud::dnsConfigured() ? HetznerCloud::forDns() : null;
        $owned = [];
        foreach ($site->dnsRecords()->orderBy('name')->orderBy('type')->get() as $record) {
            $current = null;
            if ($cloud !== null) {
                try {
                    $current = $cloud->rrset($record->zone_id, $record->name, $record->type)['values'] ?? [];
                } catch (HetznerException) {
                    $current = null;
                }
            }
            $owned[] = [
                'record' => $record,
                'host' => $record->name === '@' ? $record->zone_name : $record->name.'.'.$record->zone_name,
                'current' => $current,
                'removable' => $current === [$record->value],
            ];
        }

        return $owned;
    }

    /**
     * Removes the panel's records of a website after it was deleted, each
     * only if it still holds exactly the value the panel set. Errors are
     * written to the audit log; they never stop the deletion.
     */
    public function removeOwned(Site $site, ?User $user): void
    {
        $cloud = HetznerCloud::dnsConfigured() ? HetznerCloud::forDns() : null;
        foreach ($this->owned($site) as $entry) {
            $record = $entry['record'];
            $context = ['host' => $entry['host'], 'type' => $record->type, 'value' => $record->value];
            if (! $entry['removable'] || $cloud === null) {
                Audit::record('site.dns_record_kept', $site, [...$context, 'current' => implode(', ', $entry['current'] ?? [])], $user);

                continue;
            }
            try {
                $cloud->deleteRrset($record->zone_id, $record->name, $record->type);
                Audit::record('site.dns_record_deleted', $site, $context, $user);
            } catch (HetznerException $e) {
                Audit::record('site.dns_record_kept', $site, [...$context, 'error' => $e->errorCode], $user);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $check
     * @param  list<string>  $keys
     * @return array{done: int, error: string|null, pending: bool}
     */
    private function applyTo(Site $site, array $check, array $keys, int $ttl, User $user): array
    {
        return self::setRecords($check, $keys, $ttl, function (array $change, array $zone) use ($site, $ttl, $user): void {
            $site->dnsRecords()->updateOrCreate(
                ['name' => $change['name'], 'type' => $change['type']],
                ['zone_id' => $zone['id'], 'zone_name' => $zone['name'], 'value' => $change['expected'], 'ttl' => $ttl],
            );
            Audit::record('site.dns_record_set', $site, [
                'host' => $change['host'],
                'type' => $change['type'],
                'old' => implode(', ', $change['current']),
                'new' => $change['expected'],
                'ttl' => $ttl,
            ], $user);
        });
    }

    /**
     * Sets the chosen records of a check to exactly their expected value,
     * read from the check; $done is called after each record that was set.
     * Stops at the first error. Also used for the host name of a server
     * (E-153).
     *
     * @param  array<string, mixed>  $check  result of SiteDns::check or checkHosts
     * @param  list<string>  $keys
     * @param  callable(array<string, mixed>, array{id: string, name: string, mode: string}): void  $done
     * The request waits at most $waitSeconds in total for Hetzner; records
     * whose change is still running then count as done, and "pending" says
     * so.
     *
     * @return array{done: int, error: string|null, pending: bool}
     */
    public static function setRecords(array $check, array $keys, int $ttl, callable $done): array
    {
        $changes = array_values(array_filter(self::changes($check), fn (array $change) => in_array($change['key'], $keys, true)));
        $cloud = HetznerCloud::forDns()->withZoneDeadline(self::$waitSeconds);
        $zone = $check['zone'];
        $count = 0;

        foreach ($changes as $change) {
            try {
                $cloud->putRrset($zone['id'], $change['name'], $change['type'], [$change['expected']], $ttl,
                    $change['current'] === [] && $change['ttl'] === null ? null : ['ttl' => $change['ttl'], 'values' => $change['current']]);
            } catch (HetznerException $e) {
                return ['done' => $count, 'error' => $e->errorCode, 'pending' => $cloud->zoneActionsPending()];
            }
            $done($change, $zone);
            $count++;
        }
        foreach (array_unique(array_column($changes, 'host')) as $host) {
            app(DnsResolver::class)->forget($host);
        }

        return ['done' => $count, 'error' => null, 'pending' => $cloud->zoneActionsPending()];
    }
}
