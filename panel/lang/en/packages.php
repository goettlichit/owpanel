<?php

// Packages with limits for a whole customer (E-125).
return [
    'title' => 'Packages',
    'back' => 'Back to the packages',
    'empty' => 'No packages yet.',
    'empty_hint' => 'A package sets how many websites, databases and cron jobs a customer may have and how much storage he gets.',
    'unlimited' => 'unlimited',
    'customers_count' => 'Customers',

    'fields' => [
        'name' => 'Name',
        'name_hint' => 'For example “Webspace S”. Customers will see the name in the customer area.',
        'max_sites' => 'Websites',
        'storage_mb' => 'Storage',
        'storage_gb' => 'Storage in GB',
        'storage_hint' => 'Files and databases of all websites together, for example 5 or 0.5. It is enforced with the quota in a later step.',
        'max_databases' => 'Databases',
        'max_cron_jobs' => 'Cron jobs',
        'min_cron_interval' => 'Shortest interval of cron jobs',
        'min_cron_interval_short' => 'Cron interval',
        'min_cron_interval_hint' => 'In minutes. 5 means: at most every 5 minutes.',
        'daily_self_service' => 'Backups and restores per day',
        'daily_self_service_short' => 'Backups per day',
        'daily_self_service_hint' => 'Those the customer starts himself. Automatic backups do not count.',
        'php_memory_mb' => 'Memory per request',
        'php_memory_mb_hint' => 'Highest memory_limit a website can choose. Empty: up to :max MB.',
        'php_upload_mb' => 'Largest upload file',
        'php_upload_mb_hint' => 'Highest upload size, also the limit of nginx. Empty: up to :max MB.',
        'php_time_s' => 'Longest run time',
        'php_time_s_hint' => 'Highest run time of a request in seconds. Empty: up to :max s.',
        'php_processes' => 'PHP processes per website',
        'php_processes_hint' => 'How many requests of a website run at the same time; a website uses at most processes × memory. Empty: up to :max.',
        'empty_is_unlimited' => 'Leave empty for unlimited.',
    ],

    'php' => [
        'title' => 'PHP',
        'text' => 'Limits for every website of the customer. The customer chooses the values for his website, at most up to these. A change applies at once to all websites of the customers with this package.',
    ],

    'units' => [
        'seconds' => ':count s',
        'megabytes' => ':count MB',
        'minutes' => ':count min',
    ],

    'create' => [
        'title' => 'Create package',
        'form' => 'New package',
        'submit' => 'Create',
    ],

    'edit' => [
        'form' => 'Limits',
        'submit' => 'Save',
    ],

    'about' => [
        'title' => 'How packages work',
        'points' => [
            'A package applies to a whole customer: the limits count over all his websites.',
            'A change applies at once to all customers with this package.',
            'Once a limit is reached, the customer cannot create anything new. Nothing is ever deleted, not even after a smaller package.',
            'Limits apply only to websites assigned to a customer. Websites of the administration have none.',
            'Administrators may go beyond the limits after confirming; this is recorded in the audit log and shown on the customer.',
            'A customer without a package cannot create anything himself.',
            'Prices and contracts stay in the external system.',
        ],
    ],

    'customers' => [
        'title' => 'Customers with this package',
        'empty' => 'No customer has this package yet.',
    ],

    'delete' => [
        'title' => 'Delete package',
        'text' => 'The package is removed. No customer has it, so nothing changes for anyone.',
        'submit' => 'Delete package',
        'in_use' => 'A package that customers have cannot be deleted. Assign them another package first.',
    ],

    'toast' => [
        'created' => 'Package :name is created.',
        'updated' => 'The package is saved. The limits apply at once to all customers with it.',
        'deleted' => 'The package is deleted.',
    ],

    // Limits broken by an action (E-125, E-139).
    'limit_broken' => [
        'storage_mb' => 'The storage of the package (:max) is used up.',
        'max_sites' => 'The package allows at most :max websites.',
        'max_databases' => 'The package allows at most :max databases.',
        'max_cron_jobs' => 'The package allows at most :max cron jobs.',
        'min_cron_interval' => 'The package requires at least :interval between two runs of a cron job.',
        'daily_self_service' => 'Your package allows :max backups and restores per day; today they are used up.',
        'no_package' => 'No package is booked for your account. Please contact us.',
        'cron_locked' => 'This cron job is beyond the limit of your package and can only be deleted.',
    ],

    // Confirmation for administrators (E-139).
    'confirm' => [
        'title' => 'Package limit exceeded',
        'text' => 'As an administrator you may save anyway. This is recorded in the audit log; the customer can then only delete such a cron job.',
        'submit' => 'Save anyway',
        'cancel' => 'Cancel',
    ],

    // Notes on the pages of a website (E-139).
    'note' => [
        'unassigned' => 'The website is unassigned, no package is booked.',
        'assign' => 'Assign',
        'no_package' => 'No package is booked for the customer.',
        'choose' => 'Choose package',
        'cron' => 'Package :name: :used of :max cron jobs, shortest interval :interval.',
        'databases' => 'Package :name: :used of :max databases.',
        'over' => 'beyond the package limit',
    ],
];
