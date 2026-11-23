<?php

namespace App\Support;

/**
 * Addresses the panel calls on behalf of an administrator, such as the
 * heartbeat (E-160): only HTTPS and only public IP addresses
 * (docs/SICHERHEIT.md, API and webhooks, rules 6 and 7). The address is
 * resolved before each call and the call is pinned to the checked IP, so
 * a DNS change in between cannot lead it into the internal network.
 */
class PublicUrl
{
    /** @var (\Closure(string): list<string>)|null Replaces DNS in tests. */
    public static ?\Closure $resolver = null;

    /**
     * The checked IPs for the address, IPv4 first (ip is the first of
     * them), or the reason why it is refused ('format', 'https', 'resolve',
     * 'internal'). A caller may try the others when one cannot be reached,
     * as a browser does.
     *
     * @return array{ok: true, host: string, port: int, ip: string, ips: list<string>}|array{ok: false, reason: string}
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || strlen($url) > 500 || preg_match('/[\s\x00-\x1F]/', $url)) {
            return ['ok' => false, 'reason' => 'format'];
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return ['ok' => false, 'reason' => 'https'];
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = (int) ($parts['port'] ?? 443);

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        if ($ips === []) {
            return ['ok' => false, 'reason' => 'resolve'];
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return ['ok' => false, 'reason' => 'internal'];
            }
        }

        // IPv4 first: an IPv6 address that does not answer is a common fault
        // of web servers, and browsers hide it by falling back.
        usort($ips, fn (string $a, string $b) => str_contains($a, ':') <=> str_contains($b, ':'));
        $ips = array_slice(array_values(array_unique($ips)), 0, 4);

        return ['ok' => true, 'host' => $host, 'port' => $port, 'ip' => $ips[0], 'ips' => $ips];
    }

    /**
     * Options for the HTTP client that pin the call to the checked IP, or
     * to another of the checked IPs.
     *
     * @param  array{host: string, port: int, ip: string}  $checked
     * @return array<string, mixed>
     */
    public static function pinned(array $checked, ?string $ip = null): array
    {
        $ip ??= $checked['ip'];
        $ip = str_contains($ip, ':') ? '['.$ip.']' : $ip;

        return ['curl' => [CURLOPT_RESOLVE => ["{$checked['host']}:{$checked['port']}:{$ip}"]], 'allow_redirects' => false];
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
