<?php

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Numbers for the load pages, with the decimal separator of the interface
 * language (E-075).
 */
final class Units
{
    private const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public static function number(float|int $value, int $decimals = 1): string
    {
        return (string) Number::format($value, precision: $decimals, locale: app()->getLocale());
    }

    public static function percent(float|int $value): string
    {
        return self::number($value).' %';
    }

    /**
     * 1024-based, as df and free show it: "3,8 GB".
     */
    public static function bytes(float|int $bytes): string
    {
        $unit = 0;
        while ($bytes >= 1024 && $unit < count(self::BYTE_UNITS) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return self::number($bytes, $unit === 0 ? 0 : 1).' '.self::BYTE_UNITS[$unit];
    }

    public static function rate(float|int $bytesPerSecond): string
    {
        return __('servers.units.per_second', ['value' => self::bytes($bytesPerSecond)]);
    }

    public static function load(float|int $value): string
    {
        return self::number($value, 2);
    }
}
