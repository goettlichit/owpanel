<?php

namespace App\Firewall;

use App\Support\IpNetwork;

/**
 * Reads a blocklist (E-170). Understood are text lists with one address or
 * network at the start of each line, comments after "#", ";" or "//" (like
 * FireHOL, blocklist.de or the old Spamhaus DROP), and JSON lines with a
 * "cidr" field (Spamhaus DROP in JSON). Private, reserved and very large
 * networks are left out, so a list cannot cut off the internal network or
 * half of the internet.
 */
final class BlocklistParser
{
    /** Largest networks taken from a list. */
    public const MIN_PREFIX_V4 = 8;

    public const MIN_PREFIX_V6 = 16;

    /** Lines read at most from one list. */
    public const MAX_LINES = 1_000_000;

    /**
     * The usable entries with prefix, sorted out duplicates. ok is false
     * when the text is no blocklist: no entry at all, or more than a tenth
     * of the lines (at least five) are not understood.
     *
     * @return array{ok: bool, entries: list<string>, ignored: int, invalid: int}
     */
    public static function parse(string $text): array
    {
        $entries = [];
        $ignored = 0;
        $invalid = 0;
        $lines = 0;
        foreach (preg_split('/\R/', $text, self::MAX_LINES + 1) ?: [] as $line) {
            if (++$lines > self::MAX_LINES) {
                break;
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';') || str_starts_with($line, '//')) {
                continue;
            }
            $token = self::token($line);
            if ($token === '') {
                // JSON without an address, such as the metadata line of Spamhaus.
                continue;
            }
            $network = $token === null ? null : IpNetwork::normalize($token);
            if ($network === null) {
                $invalid++;

                continue;
            }
            if (! self::usable($network)) {
                $ignored++;

                continue;
            }
            $entries[IpNetwork::withPrefix($network)] = true;
        }

        $entries = array_keys($entries);
        $considered = count($entries) + $ignored + $invalid;

        return [
            'ok' => $entries !== [] && $invalid <= max(5, intdiv($considered, 10)),
            'entries' => $entries,
            'ignored' => $ignored,
            'invalid' => $invalid,
        ];
    }

    /**
     * The first entry that contains the address, or null. Compares the
     * binary prefixes directly, because lists have up to 250000 entries.
     *
     * @param  list<string>  $entries  networks with prefix
     */
    public static function firstContaining(array $entries, string $address): ?string
    {
        $needle = @inet_pton($address);
        if ($needle === false) {
            return null;
        }
        foreach ($entries as $entry) {
            [$base, $prefix] = explode('/', $entry, 2) + [1 => ''];
            $binary = @inet_pton($base);
            if ($binary === false || strlen($binary) !== strlen($needle)) {
                continue;
            }
            $bits = (int) $prefix;
            $bytes = intdiv($bits, 8);
            if (substr($binary, 0, $bytes) !== substr($needle, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($binary[$bytes]) ^ ord($needle[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The address of a line; '' for JSON without one, null for JSON that
     * cannot be read.
     */
    private static function token(string $line): ?string
    {
        if (str_starts_with($line, '{')) {
            $data = json_decode($line, true);
            if (! is_array($data)) {
                return null;
            }
            $value = $data['cidr'] ?? $data['ip'] ?? '';

            return is_string($value) ? $value : null;
        }

        return preg_split('/[\s,;#]+/', $line, 2)[0] ?? '';
    }

    /**
     * Whether a network may be blocked: public, and not larger than /8 for
     * IPv4 or /16 for IPv6.
     */
    private static function usable(string $network): bool
    {
        [$address, $prefix] = explode('/', IpNetwork::withPrefix($network));
        $minimum = str_contains($address, ':') ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4;
        if ((int) $prefix < $minimum) {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
