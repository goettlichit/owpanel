<?php

namespace App\Support;

/**
 * A minimal DNS client for one question to one name server over UDP, to
 * ask the authoritative servers of a zone directly (E-116). It reads only
 * A and AAAA answers; everything else is ignored.
 */
class DnsQuery
{
    public const A = 1;

    public const AAAA = 28;

    /**
     * Addresses in the answer, an empty list when the name or type does not
     * exist, or null when the server did not answer usefully.
     *
     * @return list<string>|null
     */
    public function ask(string $server, string $name, int $type, float $timeout = 2.0): ?array
    {
        $id = random_int(0, 0xFFFF);
        $target = str_contains($server, ':') ? "udp://[{$server}]:53" : "udp://{$server}:53";
        $socket = @stream_socket_client($target, $errno, $error, $timeout);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));
        @fwrite($socket, self::question($id, $name, $type));
        $response = @fread($socket, 4096);
        $timedOut = stream_get_meta_data($socket)['timed_out'] ?? true;
        fclose($socket);

        return $timedOut || ! is_string($response) ? null : self::parse($response, $id, $type);
    }

    /**
     * A query without recursion: authoritative servers answer from their zone.
     */
    public static function question(int $id, string $name, int $type): string
    {
        $encoded = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $encoded .= chr(strlen($label)).$label;
        }

        return pack('nnnnnn', $id, 0x0000, 1, 0, 0, 0).$encoded."\0".pack('nn', $type, 1);
    }

    /**
     * @return list<string>|null
     */
    public static function parse(string $packet, int $id, int $type): ?array
    {
        if (strlen($packet) < 12) {
            return null;
        }
        $header = unpack('nid/nflags/nqd/nan', substr($packet, 0, 8));
        if ($header['id'] !== $id || ($header['flags'] & 0x8000) === 0) {
            return null;
        }
        $rcode = $header['flags'] & 0x000F;
        if ($rcode === 3) {
            return [];
        }
        if ($rcode !== 0) {
            return null;
        }

        $offset = 12;
        for ($i = 0; $i < $header['qd']; $i++) {
            $offset = self::skipName($packet, $offset);
            if ($offset === null) {
                return null;
            }
            $offset += 4;
        }

        $addresses = [];
        for ($i = 0; $i < $header['an']; $i++) {
            $offset = self::skipName($packet, $offset);
            if ($offset === null || $offset + 10 > strlen($packet)) {
                return null;
            }
            $record = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            $data = substr($packet, $offset, $record['length']);
            $offset += $record['length'];
            if ($record['type'] === $type && strlen($data) === ($type === self::A ? 4 : 16)) {
                $addresses[] = (string) inet_ntop($data);
            }
        }

        return $addresses;
    }

    private static function skipName(string $packet, int $offset): ?int
    {
        $length = strlen($packet);
        while ($offset < $length) {
            $byte = ord($packet[$offset]);
            if ($byte === 0) {
                return $offset + 1;
            }
            if (($byte & 0xC0) === 0xC0) {
                return $offset + 2;
            }
            $offset += $byte + 1;
        }

        return null;
    }
}
