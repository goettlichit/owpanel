<?php

namespace App\Support;

use App\Enums\Theme;
use Illuminate\Support\Facades\Auth;

/**
 * Colour scheme for the current request: account, then session, then
 * "system". The layout writes it into <html data-theme-preference> and
 * public/js/theme.js applies it before the page is drawn.
 */
class Preferences
{
    public static function theme(): Theme
    {
        return Auth::user()?->theme
            ?? Theme::tryFrom((string) session('theme'))
            ?? Theme::System;
    }
}
