<?php

namespace App\Dns;

use App\Gateway\Ingress;
use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Models\Server;
use App\Models\Site;
use App\Support\DnsQuery;
use App\Support\DnsResolver;

/**
 * DNS of a website (E-116): for the domain and, with www, www.<domain>, the
 * A and AAAA records for the ingress addresses (E-193), what the Hetzner zone holds, what
 * the responsible name servers answer and what the panel server's resolver
 * sees. Only reads; changing follows in its own step.
 */
class SiteDns
{
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * @return array{
     *     zone: array{id: string, name: string, mode: string}|null,
     *     zone_error: string|null,
     *     hetzner: bool,
     *     nameservers: list<array{host: string, ip: string}>,
     *     rows: list<array{host: string, name: string, type: string, expected: string, zone: list<string>|null, ttl: int|null, authoritative: list<string>|null, resolver: list<string>, state: string}>,
     *     state: string
     * }
     *
     * Without $withZone the Hetzner zone is not asked, as for customers
     * (E-124, E-143).
     */
    public function check(Site $site, bool $fresh = false, bool $withZone = true): array
    {
        $hosts = array_values(array_filter([$site->domain, $site->www ? 'www.'.$site->domain : null]));

        return $this->checkAddresses(Ingress::ipv4(), Ingress::ipv6(), $hosts, $site->domain, $fresh, $withZone);
    }

    /**
     * The same for any host names that should point to a server, e.g. the
     * host name of the server itself (E-153). $zoneHost picks the zone.
     *
     * @param  list<string>  $hosts
     * @return array<string, mixed> as check()
     */
    public function checkHosts(Server $server, array $hosts, string $zoneHost, bool $fresh = false, bool $withZone = true): array
    {
        return $this->checkAddresses($server->public_ipv4, $server->public_ipv6, $hosts, $zoneHost, $fresh, $withZone);
    }

    /**
     * The same for given addresses.
     *
     * @param  list<string>  $hosts
     * @return array<string, mixed> as check()
     */
    public function checkAddresses(?string $ipv4, ?string $ipv6, array $hosts, string $zoneHost, bool $fresh = false, bool $withZone = true): array
    {
        if ($fresh) {
            foreach ($hosts as $host) {
                $this->dns->forget($host);
            }
        }

        $hetzner = $withZone && HetznerCloud::dnsConfigured();
        $zone = null;
        $zoneError = null;
        $cloud = $hetzner ? HetznerCloud::forDns() : null;
        if ($cloud !== null) {
            try {
                $zone = $cloud->zoneFor($zoneHost);
            } catch (HetznerException $e) {
                $zoneError = $e->errorCode;
            }
        }

        $rows = [];
        foreach ($hosts as $host) {
            foreach (['A' => $ipv4, 'AAAA' => $ipv6] as $type => $expected) {
                if ($expected === null || $expected === '') {
                    continue;
                }
                $expected = (string) inet_ntop((string) inet_pton($expected));
                $zoneRecords = null;
                $ttl = null;
                if ($zone !== null && $cloud !== null && $zoneError === null) {
                    try {
                        $rrset = $cloud->rrset($zone['id'], HetznerCloud::rrName($host, $zone['name']), $type);
                        $zoneRecords = array_values(array_map(fn (string $value) => @inet_pton($value) === false ? $value : (string) inet_ntop((string) inet_pton($value)), $rrset['values'] ?? []));
                        $ttl = $rrset['ttl'] ?? null;
                    } catch (HetznerException $e) {
                        $zoneError = $e->errorCode;
                    }
                }
                $authoritative = $this->dns->authoritative($host, $type === 'A' ? DnsQuery::A : DnsQuery::AAAA);
                $resolver = array_values(array_filter($this->dns->addresses($host),
                    fn (string $ip) => $type === 'A' ? (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) : (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)));

                $rows[] = [
                    'host' => $host,
                    'name' => $zone !== null ? HetznerCloud::rrName($host, $zone['name']) : $host,
                    'type' => $type,
                    'expected' => $expected,
                    'zone' => $zoneRecords,
                    'ttl' => $ttl,
                    'authoritative' => $authoritative,
                    'resolver' => $resolver,
                    'state' => self::state($expected, $zoneRecords, $authoritative, $resolver),
                ];
            }
        }

        return [
            'zone' => $zone,
            'zone_error' => $zoneError,
            'hetzner' => $hetzner,
            'nameservers' => $hosts === [] ? [] : $this->dns->nameservers($zoneHost),
            'rows' => $rows,
            'state' => self::overall($rows),
        ];
    }

    /**
     * ok: the name servers answer exactly the server address (and the zone,
     * if known, holds exactly it); waiting: correct at the name servers but
     * the resolver still sees something else; missing: no address at all;
     * wrong: something else; unknown: nobody answered.
     *
     * @param  list<string>|null  $zone
     * @param  list<string>|null  $authoritative
     * @param  list<string>  $resolver
     */
    public static function state(string $expected, ?array $zone, ?array $authoritative, array $resolver): string
    {
        $exact = fn (?array $values) => $values !== null && $values === [$expected];
        $source = $authoritative ?? $zone;
        if ($source === null) {
            return $resolver === [] ? 'unknown' : ($resolver === [$expected] ? 'ok' : 'wrong');
        }
        if ($source === []) {
            return 'missing';
        }
        if (! $exact($source) || ($zone !== null && ! $exact($zone))) {
            return 'wrong';
        }

        return $resolver === [$expected] ? 'ok' : 'waiting';
    }

    /**
     * @param  list<array{state: string}>  $rows
     */
    private static function overall(array $rows): string
    {
        $states = array_column($rows, 'state');
        foreach (['wrong', 'missing', 'unknown', 'waiting'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return $states === [] ? 'unknown' : 'ok';
    }
}
