<?php

return [
    'title' => 'My account',

    'roles' => [
        'admin' => 'Administrator',
        'customer' => 'Customer',
    ],

    'profile' => [
        'details_title' => 'Personal details',
        'email_hint' => 'Your e-mail address is your user name. An administrator can change it.',
        'save' => 'Save name',
        'title' => 'Profile',
        'role' => 'Role',
        'last_login' => 'Last sign-in',
    ],

    'security' => [
        'title' => 'Sign-in and security',
        'description' => 'How you sign in to the panel. Your password always remains the basic method.',
        'oidc_settings' => 'Pocket ID settings',
    ],

    'password' => [
        'title' => 'Change password',
        'current_password' => 'Current password',
        'password' => 'New password',
        'password_confirmation' => 'Repeat new password',
        'submit' => 'Change password',
        'change_required' => 'Please change your start password first. Your current password is the one you were given.',
    ],

    'two_factor' => [
        'enabled_since' => 'Active since :date.',
        'required' => 'Administrators must set up two-factor authentication before they can use the panel.',
        'required_for_admins' => 'It is mandatory for administrators.',
        'admin_cannot_disable' => 'Administrators cannot switch off two-factor authentication.',
        'setup_title' => 'Set up two-factor authentication',
        'setup_intro' => 'When you sign in, the panel asks for a code from an authenticator app in addition to your password, for example 2FAS, Aegis or the app of your password manager.',
        'start' => 'Start setup',
        'confirm_title' => 'Two-factor authentication: connect your app',
        'scan' => 'Scan the QR code with your authenticator app, then enter the code it shows.',
        'manual_key' => 'Or enter this key manually:',
        'confirm' => 'Confirm code',
        'active_title' => 'Two-factor authentication',
        'recovery_title' => 'Recovery codes',
        'recovery_intro' => 'Keep these codes in a safe place, for example your password manager. Each code works once if you cannot access the app. They are only shown now.',
        'regenerate' => 'Generate new recovery codes',
        'disable' => 'Switch off',
    ],

    // Messages after Fortify actions, keyed by Fortify's status codes.
    'status' => [
        'profile-information-updated' => 'Name saved.',
        'password-updated' => 'Your password has been changed.',
        'two-factor-authentication-enabled' => 'Now scan the QR code and confirm it with a code.',
        'two-factor-authentication-confirmed' => 'Two-factor authentication is active.',
        'two-factor-authentication-disabled' => 'Two-factor authentication has been switched off.',
        'recovery-codes-generated' => 'New recovery codes have been generated, the old ones no longer work.',
    ],
];
