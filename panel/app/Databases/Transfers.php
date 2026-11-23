<?php

namespace App\Databases;

use Illuminate\Support\Str;

/**
 * Files of database exports and imports (E-082). They live in one
 * directory that the web requests (uploads, downloads) and the queue
 * worker (export, import) share through the group ow10000, and are deleted
 * after one day at the latest.
 */
final class Transfers
{
    public const KEEP_HOURS = 24;

    private const NAME = '/^[0-9a-z]{26}\.(sql|sql\.gz)$/';

    public static function directory(): string
    {
        return (string) config('owpanel.transfers.path');
    }

    /**
     * A new, unused file name.
     */
    public static function newName(bool $gzip): string
    {
        return Str::lower((string) Str::ulid()).($gzip ? '.sql.gz' : '.sql');
    }

    /**
     * Full path of a file name; only names created by newName() are valid,
     * so nothing outside the directory can be reached.
     */
    public static function path(string $name): string
    {
        if (! preg_match(self::NAME, $name)) {
            throw new \InvalidArgumentException('Invalid transfer file name.');
        }

        return rtrim(self::directory(), '/').'/'.$name;
    }

    public static function exists(?string $name): bool
    {
        return $name !== null && preg_match(self::NAME, $name) === 1 && is_file(self::path($name));
    }

    public static function remove(?string $name): void
    {
        if ($name !== null && preg_match(self::NAME, $name) === 1) {
            @unlink(self::path($name));
        }
    }

    public static function ensureDirectory(): void
    {
        $directory = self::directory();
        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
    }

    /**
     * Deletes files older than KEEP_HOURS. Returns how many.
     */
    public static function prune(): int
    {
        $limit = time() - self::KEEP_HOURS * 3600;
        $count = 0;

        foreach (glob(rtrim(self::directory(), '/').'/*') ?: [] as $path) {
            if (is_file($path) && preg_match(self::NAME, basename($path)) && filemtime($path) < $limit) {
                @unlink($path);
                $count++;
            }
        }

        return $count;
    }
}
