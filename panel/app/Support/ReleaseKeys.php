<?php

namespace App\Support;

/**
 * Public release keys of the project (E-167) and their fingerprints. The
 * panel compares the keys its servers report (server-status, release_keys)
 * with the file release-signers that came with its own release.
 */
class ReleaseKeys
{
    public const FINGERPRINT_PATTERN = '/^SHA256:[A-Za-z0-9+\/]{43}$/';

    private const KEY_TYPES = [
        'ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com', 'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    /**
     * Fingerprints of the panel's own release keys; empty when the file is
     * missing or holds no key yet.
     *
     * @return list<string>
     */
    public static function expected(): array
    {
        $file = (string) config('owpanel.release_signers');

        return is_file($file) && is_readable($file)
            ? self::fingerprints((string) file_get_contents($file))
            : [];
    }

    /**
     * SHA256 fingerprints, as ssh-keygen -l shows them, of the lines
     * "owpanel-release [options] <type> <base64>" in an allowed_signers file.
     *
     * @return list<string>
     */
    public static function fingerprints(string $signers): array
    {
        $found = [];
        foreach (preg_split('/\R/', $signers) ?: [] as $line) {
            if (! str_starts_with($line, 'owpanel-release ') && ! str_starts_with($line, "owpanel-release\t")) {
                continue;
            }
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $type = array_search(true, array_map(fn ($field) => in_array($field, self::KEY_TYPES, true), $fields), true);
            $blob = $type === false ? false : base64_decode($fields[$type + 1] ?? '', true);
            if ($blob === false || $blob === '') {
                continue;
            }
            $found[] = 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        }

        return array_values(array_unique($found));
    }

    /**
     * Whether a server holds exactly the panel's keys. Null when it is not
     * known: older server scripts, or no key in the panel's release yet.
     *
     * @param  list<string>|null  $reported
     */
    public static function matches(?array $reported): ?bool
    {
        $expected = self::expected();
        if ($reported === null || $expected === []) {
            return null;
        }
        sort($expected);
        sort($reported);

        return $reported === $expected;
    }
}
