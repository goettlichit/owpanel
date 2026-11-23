<?php

namespace App\Servers;

use InvalidArgumentException;

/**
 * SSH host key of a server (E-051, E-062). Only Ed25519 keys are accepted;
 * every Debian 13 server has one.
 *
 * Keys are stored in OpenSSH format without comment, e.g. the first two
 * fields of /etc/ssh/ssh_host_ed25519_key.pub.
 */
final class HostKey
{
    public const TYPE = 'ssh-ed25519';

    /**
     * Returns "ssh-ed25519 <base64>" or throws for anything else.
     */
    public static function normalize(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key));

        if (count($parts) < 2 || count($parts) > 3 || $parts[0] !== self::TYPE) {
            throw new InvalidArgumentException('Expected an Ed25519 host key in OpenSSH format.');
        }

        $blob = base64_decode($parts[1], true);

        // Wire format: string "ssh-ed25519", string with the 32-byte key.
        $expected = pack('N', strlen(self::TYPE)).self::TYPE.pack('N', 32);
        if ($blob === false || strlen($blob) !== strlen($expected) + 32 || ! str_starts_with($blob, $expected)) {
            throw new InvalidArgumentException('Expected an Ed25519 host key in OpenSSH format.');
        }

        return self::TYPE.' '.base64_encode($blob);
    }

    public static function isValid(string $key): bool
    {
        try {
            self::normalize($key);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Fingerprint as shown by `ssh-keygen -lf`, e.g. "SHA256:abc…".
     */
    public static function fingerprint(string $key): string
    {
        $blob = base64_decode(explode(' ', self::normalize($key))[1], true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Compares the stored key with the one the server presented, in
     * constant time. A presented key in another format never matches.
     */
    public static function matches(string $stored, string $presented): bool
    {
        try {
            return hash_equals(self::normalize($stored), self::normalize($presented));
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
