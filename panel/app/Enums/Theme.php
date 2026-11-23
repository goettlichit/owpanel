<?php

namespace App\Enums;

/**
 * Colour scheme of the interface. "system" follows the operating system
 * (docs/DESIGN.md).
 */
enum Theme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public function label(): string
    {
        return __('ui.theme.'.$this->value);
    }

    public function icon(): string
    {
        return match ($this) {
            self::System => 'device-desktop',
            self::Light => 'sun',
            self::Dark => 'moon',
        };
    }
}
