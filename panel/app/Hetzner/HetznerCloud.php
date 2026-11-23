<?php

namespace App\Hetzner;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * The few calls of the Hetzner API the panel needs: snapshots (E-095):
 * find a server by its address, create a snapshot, follow the action, list
 * and delete snapshots; Storage Boxes (E-097): list them and manage one
 * subaccount per server. The token of a Hetzner project comes from
 * System -> Backup and is stored encrypted.
 */
class HetznerCloud
{
    public const BASE_URL = 'https://api.hetzner.cloud/v1';

    /**
     * Storage Boxes live in the Hetzner API, not the Cloud API; the token
     * of the project works for both (E-097).
     */
    public const STORAGE_URL = 'https://api.hetzner.com/v1';

    /**
     * Labels on every snapshot the panel creates. Rotation only ever looks
     * at images with these labels.
     */
    public const LABEL = 'owpanel';

    public const LABEL_SERVER = 'owpanel-server';

    /**
     * Value of LABEL: "snapshot" for scheduled snapshots, which rotation
     * may delete, "manual" for those started by hand, which only a person
     * deletes.
     */
    public const KIND_SCHEDULE = 'snapshot';

    public const KIND_MANUAL = 'manual';

    public function __construct(#[\SensitiveParameter] private readonly ?string $token = null) {}

    /**
     * Client with the stored token.
     */
    public static function fromSettings(): self
    {
        return new self(self::storedToken());
    }

    public static function configured(): bool
    {
        return Setting::get(Setting::HETZNER_TOKEN) !== null;
    }

    /**
     * Client for DNS zones (E-118): the own DNS token if one is stored,
     * otherwise the project token.
     */
    public static function forDns(): self
    {
        return new self(self::storedToken(Setting::HETZNER_DNS_TOKEN) ?? self::storedToken());
    }

    public static function dnsConfigured(): bool
    {
        return Setting::get(Setting::HETZNER_DNS_TOKEN) !== null || self::configured();
    }

    public static function storedToken(string $key = Setting::HETZNER_TOKEN): ?string
    {
        $encrypted = Setting::get($key);
        if ($encrypted === null) {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Checks the token with a cheap read call.
     */
    public function verify(): void
    {
        $this->get('/servers', ['per_page' => 1]);
    }

    /**
     * Checks a DNS token: it must be able to read zones.
     */
    public function verifyZones(): void
    {
        $this->get('/zones', ['per_page' => 1]);
    }

    /**
     * The Hetzner server with this public IPv4 address or in this IPv6
     * network, or null.
     *
     * @return array{id: int, name: string}|null
     */
    public function findServer(?string $ipv4, ?string $ipv6): ?array
    {
        $page = 1;
        do {
            $data = $this->get('/servers', ['page' => $page, 'per_page' => 50]);
            foreach ((array) ($data['servers'] ?? []) as $server) {
                $v4 = $server['public_net']['ipv4']['ip'] ?? null;
                $v6 = $server['public_net']['ipv6']['ip'] ?? null;
                if (($ipv4 !== null && $v4 === $ipv4) || ($ipv6 !== null && is_string($v6) && self::inNetwork($ipv6, $v6))) {
                    return ['id' => (int) $server['id'], 'name' => (string) $server['name']];
                }
            }
            $next = $data['meta']['pagination']['next_page'] ?? null;
            $page = is_int($next) ? $next : null;
        } while ($page !== null && $page <= 20);

        return null;
    }

    /**
     * Reverse DNS of a server (E-153): the PTR of its IPv4 address and the
     * PTRs set for addresses in its IPv6 network.
     *
     * @return array{ipv4: string|null, ipv4_ptr: string|null, ipv6: list<array{ip: string, dns_ptr: string}>}
     */
    public function serverPtr(int $serverId): array
    {
        $net = $this->get('/servers/'.$serverId)['server']['public_net'] ?? [];
        $ipv6 = [];
        foreach ((array) ($net['ipv6']['dns_ptr'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['ip'] ?? null) && is_string($entry['dns_ptr'] ?? null)) {
                $ipv6[] = ['ip' => (string) inet_ntop((string) inet_pton($entry['ip'])), 'dns_ptr' => $entry['dns_ptr']];
            }
        }

        return [
            'ipv4' => is_string($net['ipv4']['ip'] ?? null) ? $net['ipv4']['ip'] : null,
            'ipv4_ptr' => is_string($net['ipv4']['dns_ptr'] ?? null) ? $net['ipv4']['dns_ptr'] : null,
            'ipv6' => $ipv6,
        ];
    }

    /**
     * Sets the reverse DNS of one address of a server, or resets it to the
     * Hetzner default with null. Hetzner wants dns_ptr in every request.
     */
    public function changeDnsPtr(int $serverId, string $ip, ?string $dnsPtr): void
    {
        $this->post('/servers/'.$serverId.'/actions/change_dns_ptr', ['ip' => $ip, 'dns_ptr' => $dnsPtr]);
    }

    /**
     * Starts a snapshot of the server's disk. Returns the image and action.
     *
     * @param  array<string, string>  $labels
     * @return array{image_id: int, action_id: int}
     */
    public function createSnapshot(int $serverId, string $description, array $labels): array
    {
        $data = $this->post("/servers/{$serverId}/actions/create_image", [
            'type' => 'snapshot',
            'description' => $description,
            'labels' => $labels,
        ]);

        return ['image_id' => (int) ($data['image']['id'] ?? 0), 'action_id' => (int) ($data['action']['id'] ?? 0)];
    }

    /**
     * State of an action: running, success or error, with the error.
     *
     * @return array{status: string, error: ?string}
     */
    public function action(int $actionId): array
    {
        $action = $this->get("/actions/{$actionId}")['action'] ?? [];

        return [
            'status' => (string) ($action['status'] ?? 'running'),
            'error' => isset($action['error']['code']) ? (string) $action['error']['code'] : null,
        ];
    }

    /**
     * Snapshots of the project, optionally only those with labels. Newest
     * first.
     *
     * @return list<array{id: int, description: string, created: string, status: string, size_gb: ?float, server_id: ?int, panel: bool, manual: bool, panel_server: ?string}>
     */
    public function snapshots(?string $labelSelector = null): array
    {
        $images = [];
        $page = 1;
        do {
            $query = ['type' => 'snapshot', 'page' => $page, 'per_page' => 50, 'sort' => 'created:desc'];
            if ($labelSelector !== null) {
                $query['label_selector'] = $labelSelector;
            }
            $data = $this->get('/images', $query);
            foreach ((array) ($data['images'] ?? []) as $image) {
                $images[] = [
                    'id' => (int) $image['id'],
                    'description' => (string) ($image['description'] ?? ''),
                    'created' => (string) ($image['created'] ?? ''),
                    'status' => (string) ($image['status'] ?? ''),
                    'size_gb' => isset($image['image_size']) ? (float) $image['image_size'] : null,
                    'server_id' => isset($image['created_from']['id']) ? (int) $image['created_from']['id'] : null,
                    'panel' => in_array($image['labels'][self::LABEL] ?? null, [self::KIND_SCHEDULE, self::KIND_MANUAL], true),
                    'manual' => ($image['labels'][self::LABEL] ?? null) === self::KIND_MANUAL,
                    'panel_server' => isset($image['labels'][self::LABEL_SERVER]) ? (string) $image['labels'][self::LABEL_SERVER] : null,
                ];
            }
            $next = $data['meta']['pagination']['next_page'] ?? null;
            $page = is_int($next) ? $next : null;
        } while ($page !== null && $page <= 20);

        return $images;
    }

    /**
     * Deletes an image; one that is already gone is no error.
     */
    public function deleteImage(int $imageId): void
    {
        $this->send(fn (PendingRequest $http) => $http->delete(self::BASE_URL."/images/{$imageId}"), allowNotFound: true);
    }

    /**
     * Storage Boxes of the project.
     *
     * @return list<array{id: int, name: string, username: string, server: string, location: string, status: string}>
     */
    public function storageBoxes(): array
    {
        $boxes = [];
        $page = 1;
        do {
            $data = $this->get('/storage_boxes', ['page' => $page, 'per_page' => 50], self::STORAGE_URL);
            foreach ((array) ($data['storage_boxes'] ?? []) as $box) {
                if (! is_string($box['username'] ?? null) || ! is_string($box['server'] ?? null)) {
                    continue; // still being set up
                }
                $boxes[] = [
                    'id' => (int) $box['id'],
                    'name' => (string) ($box['name'] ?? ''),
                    'username' => $box['username'],
                    'server' => $box['server'],
                    'location' => (string) ($box['location']['name'] ?? ''),
                    'status' => (string) ($box['status'] ?? ''),
                ];
            }
            $next = $data['meta']['pagination']['next_page'] ?? null;
            $page = is_int($next) ? $next : null;
        } while ($page !== null && $page <= 20);

        return $boxes;
    }

    /**
     * Creates a subaccount with only SSH and waits until it exists.
     *
     * @param  array<string, string>  $labels
     */
    public function createSubaccount(int $boxId, string $homeDirectory, #[\SensitiveParameter] string $password, string $description, array $labels, bool $external = false): int
    {
        $data = $this->post("/storage_boxes/{$boxId}/subaccounts", [
            'home_directory' => $homeDirectory,
            'password' => $password,
            'description' => $description,
            'labels' => $labels,
            'access_settings' => [
                'ssh_enabled' => true,
                'reachable_externally' => $external,
                'samba_enabled' => false,
                'webdav_enabled' => false,
                'readonly' => false,
            ],
        ], self::STORAGE_URL);
        $this->waitForStorageAction((int) ($data['action']['id'] ?? 0));

        return (int) ($data['subaccount']['id'] ?? 0);
    }

    /**
     * Fill level of a Storage Box (E-160): used bytes including snapshots
     * and the size of its plan. Null when the box no longer exists or the
     * answer lacks the numbers.
     *
     * @return array{name: string, used: int, total: int}|null
     */
    public function storageBoxUsage(int $boxId): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get(self::STORAGE_URL."/storage_boxes/{$boxId}"), allowNotFound: true);
        $box = $response->status() === 404 ? null : $response->json('storage_box');
        $used = $box['stats']['size'] ?? null;
        $total = $box['storage_box_type']['size'] ?? null;
        if (! is_array($box) || ! is_int($used) || ! is_int($total) || $used < 0 || $total <= 0) {
            return null;
        }

        return ['name' => (string) ($box['name'] ?? '') ?: (string) ($box['username'] ?? $boxId), 'used' => $used, 'total' => $total];
    }

    /**
     * A subaccount, or null when it no longer exists.
     *
     * @return array{id: int, username: string, server: string, home_directory: string}|null
     */
    public function subaccount(int $boxId, int $subaccountId): ?array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get(self::STORAGE_URL."/storage_boxes/{$boxId}/subaccounts/{$subaccountId}"), allowNotFound: true);
        $sub = $response->status() === 404 ? null : ($response->json('subaccount') ?? null);
        if (! is_array($sub) || ! is_string($sub['username'] ?? null) || ! is_string($sub['server'] ?? null)) {
            return null;
        }

        return [
            'id' => (int) $sub['id'],
            'username' => $sub['username'],
            'server' => $sub['server'],
            'home_directory' => (string) ($sub['home_directory'] ?? ''),
        ];
    }

    /**
     * Whether the Storage Box itself (its main user) can be reached from
     * outside the Hetzner network. Only this setting is sent, so SSH, Samba
     * and WebDAV of the box stay as they are. Does not wait for the action.
     */
    public function setBoxExternal(int $boxId, bool $external): void
    {
        $this->post("/storage_boxes/{$boxId}/actions/update_access_settings", ['reachable_externally' => $external], self::STORAGE_URL);
    }

    /**
     * Whether a subaccount can be reached from outside the Hetzner network;
     * the other access settings stay as the panel created them.
     */
    public function setSubaccountExternal(int $boxId, int $subaccountId, bool $external): void
    {
        $data = $this->post("/storage_boxes/{$boxId}/subaccounts/{$subaccountId}/actions/update_access_settings", [
            'reachable_externally' => $external,
            'ssh_enabled' => true,
            'samba_enabled' => false,
            'webdav_enabled' => false,
        ], self::STORAGE_URL);
        $this->waitForStorageAction((int) ($data['action']['id'] ?? 0));
    }

    /**
     * Sets a new password for a subaccount and waits until it applies.
     */
    public function resetSubaccountPassword(int $boxId, int $subaccountId, #[\SensitiveParameter] string $password): void
    {
        $data = $this->post("/storage_boxes/{$boxId}/subaccounts/{$subaccountId}/actions/reset_subaccount_password", ['password' => $password], self::STORAGE_URL);
        $this->waitForStorageAction((int) ($data['action']['id'] ?? 0));
    }

    /**
     * Follows an action of a Storage Box for up to a minute.
     */
    private function waitForStorageAction(int $actionId, int $seconds = 60): void
    {
        if ($actionId === 0) {
            return;
        }
        $until = microtime(true) + $seconds;
        while (true) {
            $action = $this->get("/storage_boxes/actions/{$actionId}", [], self::STORAGE_URL)['action'] ?? [];
            $status = (string) ($action['status'] ?? 'running');
            if ($status === 'success') {
                return;
            }
            if ($status === 'error') {
                throw new HetznerException('action_failed', (string) ($action['error']['message'] ?? ''));
            }
            if (microtime(true) >= $until) {
                throw new HetznerException('timeout');
            }
            usleep(self::$pollMicroseconds);
        }
    }

    /**
     * Pause between two looks at an action; tests set it to zero.
     */
    public static int $pollMicroseconds = 2_000_000;

    /**
     * A password Hetzner accepts for a subaccount: letters, digits and a
     * few special characters, at least one of each kind.
     */
    public static function storagePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 28; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password.'A'.'z'.random_int(2, 9).'-_+#'[random_int(0, 3)];
    }

    /**
     * Whether an IPv6 address lies in the /64 Hetzner gives a server
     * ("2001:db8:1:2::/64").
     */
    private static function inNetwork(string $address, string $network): bool
    {
        [$base, $prefix] = array_pad(explode('/', $network), 2, '128');
        $a = @inet_pton($address);
        $b = @inet_pton($base);
        if ($a === false || $b === false || strlen($a) !== 16 || strlen($b) !== 16) {
            return false;
        }
        $bits = (int) $prefix;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;

        return $rest === 0 || ((ord($a[$bytes]) ^ ord($b[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0;
    }

    /**
     * The DNS zone of this project that holds a host name: the longest
     * matching suffix, e.g. example.de for www.shop.example.de (E-116).
     * Null when the zone is not in this project.
     *
     * @return array{id: string, name: string, mode: string}|null
     */
    public function zoneFor(string $host): ?array
    {
        $labels = explode('.', strtolower(rtrim($host, '.')));
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $name = implode('.', array_slice($labels, $i));
            foreach ($this->get('/zones', ['name' => $name])['zones'] ?? [] as $zone) {
                if (is_array($zone) && ($zone['name'] ?? null) === $name && isset($zone['id'])) {
                    return ['id' => (string) $zone['id'], 'name' => $name, 'mode' => (string) ($zone['mode'] ?? 'primary')];
                }
            }
        }

        return null;
    }

    /**
     * Records of one RRSet, or null when it does not exist.
     *
     * @return array{ttl: int|null, values: list<string>}|null
     */
    public function rrset(string $zoneId, string $name, string $type): ?array
    {
        $path = '/zones/'.rawurlencode($zoneId).'/rrsets/'.self::segment($name).'/'.rawurlencode($type);
        $response = $this->send(fn (PendingRequest $http) => $http->get(self::BASE_URL.$path), allowNotFound: true);
        $rrset = $response->status() === 404 ? null : $response->json('rrset');
        if (! is_array($rrset)) {
            return null;
        }
        $values = [];
        foreach ($rrset['records'] ?? [] as $record) {
            if (is_array($record) && is_string($record['value'] ?? null)) {
                $values[] = $record['value'];
            }
        }

        return ['ttl' => isset($rrset['ttl']) ? (int) $rrset['ttl'] : null, 'values' => $values];
    }

    /**
     * Sets the records of an RRSet to exactly these values (E-116): creates
     * the RRSet if it does not exist yet, otherwise replaces its records
     * and adjusts the TTL. Waits until Hetzner has applied the change.
     *
     * @param  list<string>  $values
     * @param  array{ttl: int|null, values: list<string>}|null  $current
     */
    public function putRrset(string $zoneId, string $name, string $type, array $values, int $ttl, ?array $current): void
    {
        $zone = '/zones/'.rawurlencode($zoneId).'/rrsets';
        $records = array_map(fn (string $value) => ['value' => $value], $values);
        if ($current === null) {
            $data = $this->post($zone, ['name' => $name, 'type' => $type, 'ttl' => $ttl, 'records' => $records]);
            $this->waitForZoneAction((int) ($data['action']['id'] ?? 0));

            return;
        }
        $path = $zone.'/'.self::segment($name).'/'.rawurlencode($type).'/actions';
        $data = $this->post($path.'/set_records', ['records' => $records]);
        $this->waitForZoneAction((int) ($data['action']['id'] ?? 0));
        if ($current['ttl'] !== $ttl) {
            $data = $this->post($path.'/change_ttl', ['ttl' => $ttl]);
            $this->waitForZoneAction((int) ($data['action']['id'] ?? 0));
        }
    }

    /**
     * Removes an RRSet (E-116), e.g. the panel's records of a deleted
     * website. A set that is already gone counts as removed.
     */
    public function deleteRrset(string $zoneId, string $name, string $type): void
    {
        $path = '/zones/'.rawurlencode($zoneId).'/rrsets/'.self::segment($name).'/'.rawurlencode($type);
        $response = $this->send(fn (PendingRequest $http) => $http->delete(self::BASE_URL.$path), allowNotFound: true);
        if ($response->status() !== 404) {
            $this->waitForZoneAction((int) ($response->json('action.id') ?? 0));
        }
    }

    /**
     * RRSet name as a path segment; "@" stays as it is, like the official
     * clients send it.
     */
    private static function segment(string $name): string
    {
        return str_replace('%40', '@', rawurlencode($name));
    }

    /**
     * Follows an action of a zone for a short while; DNS changes are
     * usually applied within seconds.
     */
    private function waitForZoneAction(int $actionId, int $seconds = 30): void
    {
        if ($actionId === 0) {
            return;
        }
        $until = microtime(true) + $seconds;
        while (true) {
            $action = $this->get('/zones/actions/'.$actionId)['action'] ?? [];
            $status = (string) ($action['status'] ?? 'running');
            if ($status === 'success') {
                return;
            }
            if ($status === 'error') {
                throw new HetznerException('action_failed', (string) ($action['error']['message'] ?? ''));
            }
            if (microtime(true) >= $until) {
                throw new HetznerException('timeout');
            }
            usleep(min(self::$pollMicroseconds, 500_000));
        }
    }

    /**
     * Name of a host inside its zone: "@" for the apex, "www" for
     * www.example.de in example.de.
     */
    public static function rrName(string $host, string $zone): string
    {
        $host = strtolower(rtrim($host, '.'));

        return $host === $zone ? '@' : substr($host, 0, -strlen('.'.$zone));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = [], string $base = self::BASE_URL): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($base.$path, $query))->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, string $base = self::BASE_URL): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post($base.$path, $body))->json() ?? [];
    }

    private function request(): PendingRequest
    {
        if ($this->token === null || $this->token === '') {
            throw new HetznerException('not_configured');
        }

        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(10)
            ->withUserAgent('OWPanel');
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, bool $allowNotFound = false): Response
    {
        try {
            $response = $call($this->request());
        } catch (ConnectionException) {
            throw new HetznerException('connection_failed');
        }

        if ($response->successful() || ($allowNotFound && $response->status() === 404)) {
            return $response;
        }

        throw new HetznerException(match ($response->status()) {
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            429 => 'rate_limited',
            default => 'api_error',
        }, (string) ($response->json('error.message') ?? ''));
    }
}
