<?php

// Customer area (E-124, E-143).
return [
    'sites' => [
        'title' => 'My websites',
        'all' => 'All websites',
        'empty' => 'No websites yet.',
        'empty_hint' => 'As soon as your provider creates a website for you, it appears here.',
        'state' => [
            'busy' => 'A change to this website is running. The page shows the new state once it is done.',
            'pending' => 'The website is being set up.',
            'deleting' => 'The website is being deleted.',
            'failed' => 'The last change failed. Please try again later or contact us.',
        ],
        'certificate' => [
            'active' => 'The website answers at https://. The certificate is renewed automatically.',
            'off' => 'The website has no certificate yet and answers over HTTP only. Before you request one, the domain must point to the server; check this under DNS.',
            'pending' => 'The certificate is requested once the website is set up.',
            'expiring' => 'The certificate expires soon. Normally it is renewed automatically; if this note stays, please contact us.',
        ],
        'www' => [
            'no_certificate' => 'There is no certificate for :www yet. Once the address points to the server, you can request it again.',
        ],
    ],

    'dns' => [
        'overall' => [
            'ok' => 'All records point to the right address.',
            'waiting' => 'The records are right but still spreading. This usually takes minutes to hours.',
            'missing' => 'At least one record is missing. Please create it at your DNS provider with the target address.',
            'wrong' => 'At least one record points elsewhere. Please change it at your DNS provider to the target address.',
            'unknown' => 'The state could not be found out right now. Please check again later.',
        ],
        'about' => [
            'Shown are the records A (IPv4) and AAAA (IPv6) for your domain and, if turned on, for www. Other records such as MX or TXT stay with your DNS provider.',
            '"Target" is the address the record must point to. "Name server answer" is what your DNS provider serves now, "Resolver answer" what visitors may still get from caches.',
            'Answers are cached for five minutes. "Check DNS" asks again at once.',
        ],
    ],

    'databases' => [
        'failed' => 'This change to the database failed. Please try again later or contact us.',
    ],

    'package' => [
        'none' => 'No package is booked for your account. You can create something new once we have assigned a package to you.',
        'cron' => 'Your package: :used of :max cron jobs, shortest interval :interval.',
        'databases' => 'Your package: :used of :max databases.',
    ],

    'backups' => [
        'left' => ':left of :max backups and restores left today.',
        'no_package' => 'No package is booked for your account, so own backups and restores are not possible.',
        'used_up' => 'All :max backups and restores of your package are used up for today. New ones are possible again from tomorrow.',
        'unlimited' => 'Backups and restores without a daily limit.',
    ],

    'limits' => [
        'storage' => 'The storage of your package is used up. Free some space first, for example by deleting old files or databases, or contact us.',
        'cron' => 'Your package allows :max cron jobs, and all are in use. To create a new one, delete an existing one first or contact us.',
        'database' => 'Your package allows :max databases, and all are in use. To create a new one, delete an existing one first or contact us.',
    ],

    'storage' => [
        'title' => 'Storage',
        'about' => [
            'Files and databases of all your websites count together.',
            'When the storage is full, your websites can no longer save files, such as uploads, caches or sessions. New databases and imports are then not possible.',
            'Measured every 15 minutes.',
        ],
        'warning' => 'Your storage is almost full. Please free some space or contact us.',
        'full' => 'Your storage is full. Your websites can no longer save files. Please free some space or contact us.',
    ],

    'suspension' => [
        'banner' => 'This website is suspended.',
        'banner_text' => 'It cannot be reached by visitors, and changes are not possible. Please contact us.',
    ],
];
