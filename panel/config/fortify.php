<?php

use App\Http\Middleware\PasswordResetAvailable;
use Laravel\Fortify\Features;

/*
 * Laravel Fortify: login, two-factor authentication, own name and password
 * (E-050). Fortify brings the logic and routes, the views are our own.
 * Registration is off: accounts are created by administrators or, for
 * the first administrator, with `php artisan owpanel:create-admin`.
 */
return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web', PasswordResetAvailable::class, 'throttle:fortify'],

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    'views' => true,

    'features' => [
        // Only reachable while outgoing mail is set up (E-094).
        Features::resetPasswords(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            // No second password prompt (E-135).
            'confirmPassword' => false,
        ]),
    ],

];
