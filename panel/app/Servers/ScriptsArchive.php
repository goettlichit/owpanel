<?php

namespace App\Servers;

use App\Enums\ServerScript;
use App\Support\PanelRelease;

/**
 * The archive of the installed release that the panel sends to its servers
 * (server-scripts-update, E-187). It lies next to the release:
 * releases/<version>/owpanel-<version>.tar.gz with its signature .sig,
 * kept by panel-update or loaded by panel-update --fetch-archive. A test
 * build has an unsigned archive with just server/ and VERSION, written by
 * its installer.
 */
final class ScriptsArchive
{
    public static function dir(): string
    {
        $dir = config('owpanel.release_dir');

        return is_string($dir) && $dir !== '' ? rtrim($dir, '/') : dirname(base_path());
    }

    public static function path(): string
    {
        return self::dir().'/owpanel-'.PanelRelease::installed().'.tar.gz';
    }

    /**
     * The armored signature, or null when there is none.
     */
    public static function signature(): ?string
    {
        $file = self::path().'.sig';
        if (! is_file($file) || filesize($file) > 4096) {
            return null;
        }
        $signature = (string) file_get_contents($file);

        return str_starts_with($signature, '-----BEGIN SSH SIGNATURE-----') ? $signature : null;
    }

    /**
     * Whether the archive can be sent: a release needs its signature.
     */
    public static function available(): bool
    {
        return is_file(self::path()) && (PanelRelease::isTestBuild() || self::signature() !== null);
    }

    /**
     * server-scripts-update with the installed version; stdin is the
     * signature (or "unsigned" for a test build), then the archive.
     */
    public static function call(): ScriptCall
    {
        $version = PanelRelease::installed();
        $head = PanelRelease::isTestBuild() ? "unsigned\n" : (self::signature() ?? '');

        return new ScriptCall(ServerScript::ServerScriptsUpdate, ['--version', $version], stdin: $head, stdinFile: self::path());
    }
}
