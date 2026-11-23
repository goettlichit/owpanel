<?php

return [
    // Locked account (E-127); shown only after a correct password.
    'locked' => 'Your access is locked. Please contact your provider.',

    // Invitation of a customer (E-128).
    'invitation' => [
        'title' => 'Set up your access',
        'intro' => 'Choose a password. Then log in with your email address and this password.',
        'invalid' => 'This link is invalid, already used or expired. Please ask your provider for a new invitation.',
        'to_login' => 'To the login',
        'submit' => 'Set password',
        'done' => 'Your password is set. You can log in now.',
    ],

    // Used by Laravel and Fortify.
    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'fields' => [
        'name' => 'Name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'E-mail address',
        'password' => 'Password',
    ],

    'login' => [
        'title' => 'Sign in',
        'remember' => 'Keep me signed in',
        'submit' => 'Sign in',
    ],

    'oidc' => [
        'login' => 'Sign in with :name',
        'or' => 'or',
        'title' => 'Sign-in with :name',
        'intro' => 'Link your account with :name, then you sign in there with your passkey. Password and two-factor stay available as well.',
        'linked_as' => 'Linked with :account',
        'linked_since' => 'since :date',
        'last_login' => 'last sign-in :date',
        'never' => 'not used to sign in yet',
        'not_set_up' => 'Sign-in with :name is not set up yet.',
        'not_linked' => 'Not linked yet.',
        'link' => 'Link with :name',
        'unlink' => 'Remove link',
        'other_issuer' => 'The link belongs to an earlier address and no longer works. Remove it and link again.',
        'linked' => 'Your account is now linked with :name.',
        'unlinked' => 'The link with :name has been removed.',
        'errors' => [
            'not_configured' => 'The sign-in with :name is not set up.',
            'connection_failed' => ':name cannot be reached right now. Please sign in with your password.',
            'expired' => 'The sign-in took too long or was started again. Please try once more.',
            'cancelled' => 'The sign-in at :name was cancelled.',
            'not_linked' => 'This account at :name is not linked with any panel account. Sign in with your password and link it under My account → Sign-in and security.',
            'not_allowed' => 'Sign-in with :name is not turned on for your account. Please sign in with your password.',
            'taken' => 'This account at :name is already linked with another panel account. To link, sign in at :name with the right account, signing out there first if needed.',
            'taken_account' => 'The account :account at :name is already linked with another panel account. To link, sign in at :name with the right account, signing out there first or choosing another passkey if needed.',
            'failed' => 'The sign-in with :name failed. Please try again or sign in with your password.',
        ],
    ],

    'forgot' => [
        'link' => 'Forgot password?',
        'title' => 'Forgot password',
        'intro' => 'Enter the e-mail address of your account. We will send you a link to set a new password.',
        'submit' => 'Send link',
        'back' => 'Back to sign in',
        'sent' => 'If there is an account with this address, the link is on its way. Also check the spam folder.',
        'throttled' => 'Too many requests. Please try again in a few minutes.',
    ],

    'reset' => [
        'title' => 'New password',
        'intro' => 'Set a new password for your account. Two-factor authentication stays on.',
        'password' => 'New password',
        'confirmation' => 'Repeat new password',
        'hint' => 'At least 12 characters.',
        'submit' => 'Save password',
    ],

    'two_factor' => [
        'title' => 'Two-factor confirmation',
        'intro' => 'Enter the six-digit code from your authenticator app.',
        'code' => 'Code',
        'submit' => 'Confirm',
        'use_recovery_code' => 'No access to the app? Use a recovery code',
        'recovery_code' => 'Recovery code',
        'submit_recovery' => 'Sign in with recovery code',
    ],

    'confirm' => [
        'title' => 'Confirm password',
        'intro' => 'Please confirm your password before continuing.',
        'submit' => 'Confirm',
    ],
];
