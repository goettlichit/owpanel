<?php

namespace App\Gateway;

use App\Models\Server;
use App\Models\Setting;

/**
 * The addresses visitors reach the websites at (E-162, M5-3): one IPv4 and
 * one IPv6 for all websites, on the panel server as the gateway. Without
 * an own setting they are the public addresses of the panel server, so a
 * standalone installation needs nothing. A second gateway (E-164) gets
 * floating addresses here; the IPv6 per website follows with M5-7.
 *
 * The DNS of a website points here, not to the server it runs on.
 */
class Ingress
{
    /**
     * The IPv4 for the A records of all websites, or null.
     */
    public static function ipv4(): ?string
    {
        return self::normal(Setting::get(Setting::INGRESS_IPV4)) ?? self::normal(Server::panelHost()?->public_ipv4);
    }

    /**
     * The IPv6 for the AAAA records of all websites, or null.
     */
    public static function ipv6(): ?string
    {
        return self::normal(Setting::get(Setting::INGRESS_IPV6)) ?? self::normal(Server::panelHost()?->public_ipv6);
    }

    /**
     * Both, without the missing one.
     *
     * @return list<string>
     */
    public static function addresses(): array
    {
        return array_values(array_filter([self::ipv4(), self::ipv6()]));
    }

    /**
     * Whether an own address is set instead of the panel server's.
     */
    public static function custom(): bool
    {
        return Setting::get(Setting::INGRESS_IPV4) !== null || Setting::get(Setting::INGRESS_IPV6) !== null;
    }

    /**
     * Stores own addresses; null uses the panel server's again.
     */
    public static function save(?string $ipv4, ?string $ipv6): void
    {
        Setting::put(Setting::INGRESS_IPV4, self::normal($ipv4));
        Setting::put(Setting::INGRESS_IPV6, self::normal($ipv6));
    }

    /**
     * Whether visitors reach this server directly at the ingress addresses,
     * so its websites' domains also work for SFTP (standalone, gateway).
     */
    public static function reaches(Server $server): bool
    {
        $own = array_filter([self::normal($server->public_ipv4), self::normal($server->public_ipv6)]);

        return $own !== [] && array_intersect($own, self::addresses()) !== [];
    }

    /**
     * Rules for a public address of the given family; private, reserved
     * and local addresses are refused, because visitors could not reach them.
     *
     * @return list<string>
     */
    public static function rules(int $family): array
    {
        return ['nullable', 'ip', $family === 4 ? 'ipv4' : 'ipv6', function (string $attribute, mixed $value, \Closure $fail) {
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $fail(__('settings.ingress.not_public'));
            }
        }];
    }

    /**
     * The canonical form of an address, or null.
     */
    private static function normal(?string $address): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }
        $packed = @inet_pton(trim($address));

        return $packed === false ? null : (string) inet_ntop($packed);
    }
}
