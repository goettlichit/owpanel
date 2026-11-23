<?php

namespace App\Sites;

use App\Models\Package;
use App\Models\Site;

/**
 * PHP settings of a website (E-173): memory_limit, upload size and run time
 * the website chose, the processes its package allows, and whether errors
 * are shown. Values above the package of the customer are cut to it, for
 * customers and administrators alike; websites of the administration and
 * unassigned ones are only bound by what the server scripts accept.
 */
class PhpSettings
{
    /**
     * Settings a website can choose, with the column on the website and the
     * limit in the package.
     */
    public const CHOSEN = [
        'memory' => ['site' => 'php_memory_mb', 'package' => 'php_memory_mb'],
        'upload' => ['site' => 'php_upload_mb', 'package' => 'php_upload_mb'],
        'time' => ['site' => 'php_time_s', 'package' => 'php_time_s'],
    ];

    /**
     * Lowest and highest value for each setting of this website.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public static function bounds(Site $site): array
    {
        $package = self::package($site);
        $bounds = [];
        foreach ([...array_keys(self::CHOSEN), 'processes'] as $key) {
            $min = (int) config("owpanel.php_settings.min.{$key}");
            $max = (int) config("owpanel.php_settings.max.{$key}");
            $limit = $package?->{$key === 'processes' ? 'php_processes' : self::CHOSEN[$key]['package']};
            if ($limit !== null) {
                $max = max($min, min($max, (int) $limit));
            }
            $bounds[$key] = ['min' => $min, 'max' => $max];
        }

        return $bounds;
    }

    /**
     * What the server gets: the website's values or the defaults, cut to
     * the bounds; processes from the package.
     *
     * @return array{memory: int, upload: int, time: int, processes: int, errors: bool}
     */
    public static function effective(Site $site): array
    {
        $bounds = self::bounds($site);
        $values = [];
        foreach (self::CHOSEN as $key => $columns) {
            $value = $site->{$columns['site']} ?? (int) config("owpanel.php_settings.defaults.{$key}");
            $values[$key] = max($bounds[$key]['min'], min($bounds[$key]['max'], (int) $value));
        }
        $package = self::package($site);
        $processes = $package?->php_processes ?? (int) config('owpanel.php_settings.defaults.processes');
        $values['processes'] = max($bounds['processes']['min'], min($bounds['processes']['max'], (int) $processes));
        $values['errors'] = (bool) $site->php_display_errors;

        return $values;
    }

    /**
     * Whether the customer of the website may change its settings: only
     * with a package (E-125); administrators always.
     */
    public static function customerMayChange(Site $site): bool
    {
        return $site->customer_id === null || self::package($site) !== null;
    }

    private static function package(Site $site): ?Package
    {
        return $site->customer?->package;
    }
}
