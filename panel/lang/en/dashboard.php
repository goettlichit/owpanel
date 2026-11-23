<?php

return [
    'title' => 'Overview',
    'pretitle' => 'OWPanel',

    // Left menu of the overview; monitoring and notifications follow later.
    'menu' => [
        'summary' => 'Summary',
    ],

    'welcome' => [
        'title' => 'Welcome',
        'text' => 'The panel is under construction. Servers, websites and running tasks will appear here soon.',
    ],

    'stats' => [
        'users' => 'Users',
        'admins_hint' => '{1} including :count administrator|[0,*] including :count administrators',
        'sites' => 'Websites',
        'sites_hint' => '{1} :tls with certificate, :count database|[0,*] :tls with certificate, :count databases',
        'failed_logins' => 'Failed sign-ins (24 h)',
        'failed_logins_hint' => 'Failed sign-in attempts',
        'lockouts_hint' => '{1} :count lockout after too many attempts|[0,*] :count lockouts after too many attempts',
        'check' => 'Check',
        'two_factor' => 'Two-factor',
        'all_set' => 'All active',
        'two_factor_ok' => 'All administrators use two-factor',
        'two_factor_missing' => '{1} :count administrator without two-factor|[0,*] :count administrators without two-factor',
        'action_needed' => 'Action needed',
    ],

    'servers' => [
        'title' => 'Servers',
        'all_good' => 'All servers can be reached and are up to date, all certificates are valid.',
        'attention' => 'Some servers need attention.',
        'none' => 'No server has been added yet.',
        'certificates' => 'Certificates',
        'panel_certificate' => 'Certificate of the panel',
        'expires' => 'expires on :date',
        'expired' => 'expired on :date',
        'all' => 'All servers',
    ],
];
