<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Small DNS lookups through the resolver of the panel server, for hints in
 * the interface (e.g. whether a website's domain already points to its
 * server). Results are cached for five minutes, so a slow resolver does not
 * slow every page view.
 */
class DnsResolver
{
    private const CACHE_SECONDS = 300;

    /**
     * IPv4 and IPv6 addresses of a host name, normalised; empty when the
     * name does not resolve.
     *
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || strlen($host) > 253) {
            return [];
        }

        return Cache::remember('owpanel.dns.'.sha1($host), self::CACHE_SECONDS, function () use ($host): array {
            $records = @dns_get_record($host.'.', DNS_A | DNS_AAAA);
            $addresses = [];
            foreach (is_array($records) ? $records : [] as $record) {
                $address = self::normalise((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
                if ($address !== null) {
                    $addresses[] = $address;
                }
            }

            return array_values(array_unique($addresses));
        });
    }

    /**
     * True when the name resolves and every address belongs to the given
     * ones. An AAAA record pointing elsewhere would send IPv6 visitors to
     * another server, so one match is not enough.
     *
     * @param  list<string|null>  $own
     */
    public function pointsTo(string $host, array $own): bool
    {
        $own = array_filter(array_map(fn ($address) => self::normalise((string) $address), $own));
        $resolved = $this->addresses($host);

        return $own !== [] && $resolved !== [] && array_diff($resolved, $own) === [];
    }

    /**
     * Name servers responsible for a host: the NS records of the nearest
     * zone above it, with their IPv4 addresses (at most three servers).
     *
     * @return list<array{host: string, ip: string}>
     */
    public function nameservers(string $host): array
    {
        $labels = explode('.', strtolower(rtrim($host, '.')));

        return Cache::remember('owpanel.dns.ns.'.sha1(implode('.', $labels)), self::CACHE_SECONDS, function () use ($labels): array {
            for ($i = 0; $i < count($labels) - 1; $i++) {
                $records = @dns_get_record(implode('.', array_slice($labels, $i)).'.', DNS_NS);
                $servers = [];
                foreach (is_array($records) ? $records : [] as $record) {
                    $target = strtolower((string) ($record['target'] ?? ''));
                    foreach ($target === '' ? [] : $this->addresses($target) as $ip) {
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $servers[] = ['host' => $target, 'ip' => $ip];
                            break;
                        }
                    }
                }
                if ($servers !== []) {
                    usort($servers, fn ($a, $b) => strcmp($a['host'], $b['host']));

                    return array_slice($servers, 0, 3);
                }
            }

            return [];
        });
    }

    /**
     * What the responsible name servers answer for A or AAAA: the first
     * server that answers counts. Null when none answers.
     *
     * @return list<string>|null
     */
    public function authoritative(string $host, int $type): ?array
    {
        foreach ($this->nameservers($host) as $server) {
            $answer = app(DnsQuery::class)->ask($server['ip'], strtolower(rtrim($host, '.')), $type);
            if ($answer !== null) {
                return array_values(array_filter(array_map(fn ($address) => self::normalise($address), $answer)));
            }
        }

        return null;
    }

    /**
     * Reverse DNS of an address from the system resolver (E-153), or null.
     */
    public function ptr(string $ip): ?string
    {
        $name = @gethostbyaddr($ip);

        return is_string($name) && $name !== $ip && $name !== '' ? strtolower(rtrim($name, '.')) : null;
    }

    /**
     * Forgets cached answers for a host, for "Check DNS".
     */
    public function forget(string $host): void
    {
        $host = strtolower(rtrim($host, '.'));
        Cache::forget('owpanel.dns.'.sha1($host));
        Cache::forget('owpanel.dns.ns.'.sha1($host));
    }

    private static function normalise(string $address): ?string
    {
        $packed = @inet_pton($address);

        return $packed === false ? null : inet_ntop($packed);
    }
}
