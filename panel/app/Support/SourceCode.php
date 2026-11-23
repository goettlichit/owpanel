<?php

namespace App\Support;

/**
 * Link to the source code of the running version.
 *
 * AGPL-3.0-or-later, section 13: users who interact with the panel over a
 * network must be able to get its source. Releases link to their tag,
 * development checkouts to the repository.
 */
class SourceCode
{
    public static function version(): string
    {
        return (string) config('owpanel.version');
    }

    public static function url(): string
    {
        $base = rtrim((string) config('owpanel.source_url'), '/');
        $version = self::version();

        if (self::isRelease()) {
            return $base.'/tree/v'.$version;
        }

        return $base;
    }

    /**
     * Link to a file or folder of the running version, e.g. "docs" or
     * "CHANGELOG.md". Development checkouts link to the main branch.
     */
    public static function file(string $path): string
    {
        $base = rtrim((string) config('owpanel.source_url'), '/');
        $ref = self::isRelease() ? 'v'.self::version() : 'main';
        $kind = str_contains(basename($path), '.') ? 'blob' : 'tree';

        return $base.'/'.$kind.'/'.$ref.'/'.ltrim($path, '/');
    }

    public static function issues(): string
    {
        return rtrim((string) config('owpanel.source_url'), '/').'/issues';
    }

    private static function isRelease(): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', self::version()) === 1;
    }
}
