<?php

// Panel accounts (E-081).
return [
    'title' => 'Users',

    'fields' => [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'name' => 'Name',
        'email' => 'Email address',
        'role' => 'Role',
        'two_factor' => 'Two-factor',
        'last_login' => 'Last sign-in',
        'actions' => 'Actions',
    ],

    'roles' => [
        'admin' => 'Administrator',
        'customer' => 'Customer',
    ],

    'list' => [
        'title' => 'Panel accounts',
        'hint' => 'Customer accounts come with the customer area.',
        'you' => 'You',
        'two_factor_on' => 'active',
        'two_factor_off' => 'not set up',
        'not_signed_in' => 'not signed in yet',
    ],

    'oidc' => [
        'customer_access' => 'Manage at the customer',
        'state_linked' => 'linked',
        'state_always' => 'allowed',
        'state_allowed' => 'turned on',
        'state_off' => 'off',
        'allow' => 'Turn on',
        'revoke' => 'Turn off',
        'allowed' => ':name turned on for :email. The customer links the account once.',
        'revoked' => ':name turned off for :email; an existing link was removed.',
    ],

    'create' => [
        'title' => 'Add administrator',
        'form' => 'New administrator',
        'submit' => 'Create',
        'role_hint' => 'Administrators may do everything in the panel. Customer accounts with limited rights follow with the customer area.',
        'about_title' => 'What happens next',
        'steps' => [
            'The panel creates a start password and shows it to you once.',
            'Pass on email address and start password in a safe way.',
            'The password must be changed at the first sign-in.',
            'Then the new administrator sets up two-factor authentication; nothing else is possible before that.',
        ],
    ],

    'created' => [
        'title' => ':name has been created',
        'text' => 'Pass on these credentials in a safe way. The password must be changed at the first sign-in.',
        'password' => 'Start password',
        'once' => 'The start password is shown only now and is not stored anywhere.',
    ],

    'delete' => [
        'button' => 'Delete',
        'confirm' => 'Enter :email to confirm',
        'submit' => 'Delete for good',
        'mismatch' => 'The email address does not match.',
        'done' => 'The account :email has been deleted.',
    ],
];
