<?php

namespace App\Support;

/**
 * IPv4 and IPv6 addresses and networks as the firewall and fail2ban expect
 * them (E-090): one address, or a network with prefix whose host bits are
 * zero. Input like "203.0.113.17/24" becomes "203.0.113.0/24".
 */
final class IpNetwork
{
    /**
     * Returns the canonical form, an address without prefix for a single
     * host, or null if the input is no address or network.
     */
    public static function normalize(string $input): ?string
    {
        $input = trim($input);
        if (! preg_match('#^([0-9a-fA-F:.]{2,45})(?:/(\d{1,3}))?$#', $input, $match)) {
            return null;
        }
        $binary = @inet_pton($match[1]);
        if ($binary === false || filter_var($match[1], FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $bits = strlen($binary) * 8;
        $prefix = isset($match[2]) ? (int) $match[2] : $bits;
        if ($prefix < 1 || $prefix > $bits) {
            return null;
        }

        // Clears the host bits.
        $masked = '';
        for ($i = 0; $i < strlen($binary); $i++) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $masked .= chr(ord($binary[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
        }
        $address = (string) inet_ntop($masked);
        // IPv4 inside IPv6 (::ffff:1.2.3.4) is meant as IPv4; written so, it
        // would not match.
        if (str_contains($address, '.') && str_contains($address, ':')) {
            return null;
        }

        return $prefix === $bits ? $address : $address.'/'.$prefix;
    }

    /**
     * With prefix in any case, as nft needs it: /32 or /128 for one host.
     */
    public static function withPrefix(string $normalized): string
    {
        if (str_contains($normalized, '/')) {
            return $normalized;
        }

        return $normalized.(str_contains($normalized, ':') ? '/128' : '/32');
    }

    /**
     * Whether an address lies in an address or network.
     */
    public static function contains(string $network, string $address): bool
    {
        $network = self::withPrefix($network);
        [$base, $prefix] = explode('/', $network);
        $baseBinary = @inet_pton($base);
        $addressBinary = @inet_pton($address);
        if ($baseBinary === false || $addressBinary === false || strlen($baseBinary) !== strlen($addressBinary)) {
            return false;
        }

        return self::normalize($address.'/'.$prefix) === self::normalize($network);
    }
}
