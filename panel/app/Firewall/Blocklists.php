<?php

namespace App\Firewall;

use App\Models\Blocklist;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Support\IpNetwork;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches blocklists and hands them to the servers (E-170).
 */
final class Blocklists
{
    /**
     * Fetches a list again. On failure the cached entries stay in use.
     *
     * @return array{ok: bool, changed: bool, error: ?string, detail: ?string}
     */
    public static function refresh(Blocklist $list): array
    {
        $result = BlocklistFetcher::fetch($list->url);
        $list->checked_at = now();
        if (! $result['ok']) {
            $list->error = $result['error'];
            $list->error_detail = ($result['detail'] ?? '') !== '' ? $result['detail'] : null;
            $list->failures = min(65535, $list->failures + 1);
            $list->save();

            return ['ok' => false, 'changed' => false, 'error' => $result['error'], 'detail' => $list->error_detail];
        }

        $changed = $list->content_hash !== $result['hash'];
        if ($changed) {
            $list->cache($result['entries']);
            $list->content_hash = $result['hash'];
            $list->changed_at = now();
        }
        $list->entries = count($result['entries']);
        $list->ignored = $result['ignored'];
        $list->error = null;
        $list->error_detail = null;
        $list->failures = 0;
        $list->save();

        return ['ok' => true, 'changed' => $changed, 'error' => null, 'detail' => null];
    }

    /**
     * Loads all lists of the server onto it. The task builds its list when
     * it runs, so the last change wins.
     */
    public static function apply(Server $server, ?User $user): ServerTask
    {
        return ServerTask::start($server, ScriptCall::serverBlocklist([], []), $user);
    }

    /**
     * The hits of the blocklists as the firewall page shows them (E-170):
     * the totals, the 20 most frequent addresses with the lists they are on,
     * and per list the packets and addresses among the read hits (the 1000
     * most frequent addresses).
     *
     * @param  Collection<int, Blocklist>  $lists
     * @param  array<string, mixed>  $hits  answer of ReadBlocklistHits
     * @param  array<string, BlocklistIndex>|null  $indexes  from indexes(), built here if missing
     * @return array{error: ?string, counting: bool, addresses: int, packets: int, bytes: int, partial: bool, top: list<array{ip: string, packets: int, bytes: int, seen: int, lists: list<string>}>, per_list: array<string, array{packets: int, addresses: int}>}
     */
    public static function hits(Collection $lists, array $hits, ?array $indexes = null): array
    {
        $result = ['error' => null, 'counting' => true, 'addresses' => 0, 'packets' => 0, 'bytes' => 0, 'partial' => false, 'top' => [], 'per_list' => []];
        if (isset($hits['error'])) {
            $result['error'] = (string) $hits['error'];

            return $result;
        }
        $result['counting'] = (bool) ($hits['counting'] ?? true);
        $top = $hits['top'] ?? [];
        $result['addresses'] = (int) ($hits['addresses'] ?? 0);
        $result['packets'] = (int) ($hits['packets'] ?? 0);
        $result['bytes'] = (int) ($hits['bytes'] ?? 0);
        $result['partial'] = $result['addresses'] > count($top);
        if ($top === []) {
            return $result;
        }

        $indexes ??= self::indexes($lists);
        $origins = [];
        foreach ($lists as $list) {
            $index = $indexes[(string) $list->getKey()];
            $stats = ['packets' => 0, 'addresses' => 0];
            foreach ($top as $i => $hit) {
                if ($index->contains($hit['ip'])) {
                    $origins[$i][] = $list->label();
                    $stats['packets'] += $hit['packets'];
                    $stats['addresses']++;
                }
            }
            $result['per_list'][(string) $list->getKey()] = $stats;
        }
        foreach (array_slice($top, 0, 20, true) as $i => $hit) {
            $result['top'][] = $hit + ['lists' => $origins[$i] ?? []];
        }

        return $result;
    }

    /**
     * One index per list, keyed by the list's ID.
     *
     * @param  Collection<int, Blocklist>  $lists
     * @return array<string, BlocklistIndex>
     */
    public static function indexes(Collection $lists): array
    {
        $indexes = [];
        foreach ($lists as $list) {
            $indexes[(string) $list->getKey()] = self::index($list);
        }

        return $indexes;
    }

    /**
     * The index of one list, kept in the file cache until the list changes
     * (its content hash is part of the key): building it reads up to 250000
     * entries from the database, which the firewall page did on every visit.
     * Only plain arrays are cached, the cache allows no objects.
     */
    private static function index(Blocklist $list): BlocklistIndex
    {
        if ($list->content_hash === null) {
            return new BlocklistIndex($list->cachedEntries());
        }
        $key = 'blocklist-index:'.$list->getKey().':'.$list->content_hash;
        $networks = Cache::store('file')->get($key);
        if (is_array($networks)) {
            return BlocklistIndex::fromNetworks($networks);
        }
        $index = new BlocklistIndex($list->cachedEntries());
        Cache::store('file')->put($key, $index->networks(), now()->addDays(2));

        return $index;
    }

    /**
     * Names of the lists an address is on.
     *
     * @param  Collection<int, Blocklist>  $lists
     * @param  array<string, BlocklistIndex>  $indexes
     * @return list<string>
     */
    public static function listsWith(Collection $lists, array $indexes, string $address): array
    {
        $names = [];
        foreach ($lists as $list) {
            if ($indexes[(string) $list->getKey()]->contains($address)) {
                $names[] = $list->label();
            }
        }

        return $names;
    }

    /**
     * Whether the server never blocks an address: the addresses of the
     * panel server and the fail2ban exceptions come before the lists.
     */
    public static function excepted(Server $server, string $address): bool
    {
        $panel = Server::panelHost();
        foreach ([$panel?->public_ipv4, $panel?->public_ipv6] as $own) {
            if (is_string($own) && IpNetwork::normalize($own) === $address) {
                return true;
            }
        }

        return $server->fail2banIgnores()->pluck('address')
            ->contains(fn (string $ignore) => IpNetwork::contains($ignore, $address));
    }

    /**
     * Own addresses that are on one of the server's lists: the public
     * addresses of all servers, the administrator's current address and
     * the fail2ban exceptions (a network by its first address).
     *
     * @param  Collection<int, Blocklist>  $lists
     * @param  array<string, BlocklistIndex>  $indexes
     * @return list<array{address: string, kind: string, name: ?string, lists: list<string>, excepted: bool}>
     */
    public static function ownMatches(Server $server, Collection $lists, array $indexes, ?string $clientIp): array
    {
        $candidates = [];
        foreach (Server::query()->orderBy('name')->get() as $each) {
            foreach ([$each->public_ipv4, $each->public_ipv6] as $address) {
                if (is_string($address) && ($normalized = IpNetwork::normalize($address)) !== null && ! str_contains($normalized, '/')) {
                    $candidates[] = ['address' => $normalized, 'kind' => 'server', 'name' => $each->name];
                }
            }
        }
        if ($clientIp !== null && ! str_contains($clientIp, '/')) {
            $candidates[] = ['address' => $clientIp, 'kind' => 'client', 'name' => null];
        }
        foreach ($server->fail2banIgnores()->orderBy('created_at')->orderBy('id')->get() as $ignore) {
            $candidates[] = ['address' => explode('/', $ignore->address)[0], 'kind' => 'exception', 'name' => $ignore->comment];
        }

        $matches = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate['address']])) {
                continue;
            }
            $seen[$candidate['address']] = true;
            $names = self::listsWith($lists, $indexes, $candidate['address']);
            if ($names !== []) {
                $matches[] = $candidate + ['lists' => $names, 'excepted' => self::excepted($server, $candidate['address'])];
            }
        }

        return $matches;
    }

    /**
     * Entries of all other lists of the server, to keep the total in limits.
     */
    public static function entriesBesides(Server $server, ?Blocklist $except = null): int
    {
        return (int) $server->blocklists()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->sum('entries');
    }
}
