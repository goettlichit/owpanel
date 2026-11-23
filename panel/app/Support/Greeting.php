<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * "Guten Morgen, Alexander": greeting by time of day in the panel's
 * display time zone (config/owpanel.php).
 */
class Greeting
{
    public static function for(User $user, ?Carbon $now = null): string
    {
        $hour = ($now ?? now())->copy()->setTimezone(config('owpanel.timezone'))->hour;

        $key = match (true) {
            $hour >= 5 && $hour < 11 => 'morning',
            $hour >= 11 && $hour < 18 => 'day',
            default => 'evening',
        };

        return __('ui.greeting.'.$key, ['name' => $user->first_name]);
    }
}
