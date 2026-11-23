<?php

namespace App\Dns;

use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Models\Server;
use App\Support\DnsResolver;

/**
 * DNS of a server (E-153): its host name should point to its addresses
 * (A and AAAA, checked like a website), and each address back to the host
 * name (reverse DNS, PTR). For Hetzner servers the PTRs come from and go to
 * the Hetzner Cloud API; otherwise they are only looked up.
 */
class ServerDns
{
    public function __construct(private readonly SiteDns $sites, private readonly DnsResolver $dns) {}

    /**
     * Full host name with at least one dot, or null.
     */
    public static function hostName(Server $server): ?string
    {
        $name = strtolower(rtrim((string) ($server->hostname ?: $server->name), '.'));

        return self::validName($name) ? $name : null;
    }

    public static function validName(string $name): bool
    {
        return strlen($name) <= 253 && str_contains($name, '.')
            && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) === 1;
    }

    /**
     * @return array{
     *     host: string|null,
     *     records: array<string, mixed>|null,
     *     ptr: list<array{ip: string, type: string, current: string|null, forward: bool|null, state: string}>,
     *     hetzner_id: int|null,
     *     ptr_error: string|null
     * }
     */
    public function check(Server $server, bool $fresh = false): array
    {
        $host = self::hostName($server);
        $records = $host === null ? null : $this->sites->checkHosts($server, [$host], $host, $fresh);

        [$hetznerId, $hetznerPtr, $error] = $this->hetznerPtr($server);
        $rows = [];
        foreach (['IPv4' => $server->public_ipv4, 'IPv6' => $server->public_ipv6] as $type => $ip) {
            if ($ip === null || $ip === '' || @inet_pton($ip) === false) {
                continue;
            }
            $ip = (string) inet_ntop((string) inet_pton($ip));
            $current = $hetznerPtr !== null ? ($hetznerPtr[$ip] ?? null) : $this->dns->ptr($ip);
            if ($current !== null && $fresh) {
                $this->dns->forget($current);
            }
            $forward = $current === null ? null : in_array($ip, array_map(
                fn (string $address) => (string) inet_ntop((string) inet_pton($address)),
                array_filter($this->dns->addresses($current), fn (string $address) => @inet_pton($address) !== false),
            ), true);
            $rows[] = [
                'ip' => $ip,
                'type' => $type,
                'current' => $current,
                'forward' => $forward,
                'state' => match (true) {
                    $current === null => 'missing',
                    $host !== null && $current !== $host => 'wrong',
                    $forward === false => 'waiting',
                    default => 'ok',
                },
            ];
        }

        return ['host' => $host, 'records' => $records, 'ptr' => $rows, 'hetzner_id' => $hetznerId, 'ptr_error' => $error];
    }

    /**
     * Sets the PTR of one address of the server through Hetzner.
     */
    public function setPtr(Server $server, string $ip, ?string $name): void
    {
        $id = $this->hetznerId($server) ?? throw new HetznerException('server_not_found');
        HetznerCloud::fromSettings()->changeDnsPtr($id, $ip, $name);
    }

    /**
     * @return array{0: int|null, 1: array<string, string>|null, 2: string|null}
     */
    private function hetznerPtr(Server $server): array
    {
        if (! HetznerCloud::configured()) {
            return [null, null, null];
        }
        try {
            $id = $this->hetznerId($server);
            if ($id === null) {
                return [null, null, null];
            }
            $data = HetznerCloud::fromSettings()->serverPtr($id);
        } catch (HetznerException $e) {
            return [null, null, $e->errorCode];
        }
        $ptr = [];
        if ($data['ipv4'] !== null && $data['ipv4_ptr'] !== null) {
            $ptr[$data['ipv4']] = $data['ipv4_ptr'];
        }
        foreach ($data['ipv6'] as $entry) {
            $ptr[$entry['ip']] = $entry['dns_ptr'];
        }

        return [$id, $ptr, null];
    }

    private function hetznerId(Server $server): ?int
    {
        if ($server->hetzner_server_id === null && HetznerCloud::configured()) {
            $found = HetznerCloud::fromSettings()->findServer($server->public_ipv4, $server->public_ipv6);
            if ($found !== null) {
                $server->forceFill(['hetzner_server_id' => $found['id']])->save();
            }
        }

        return $server->hetzner_server_id;
    }
}
