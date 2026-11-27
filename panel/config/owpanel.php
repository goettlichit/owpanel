<?php

return [

    /*
    | Version of the running panel. Releases set it, a development checkout
    | reports "dev".
    */
    'version' => env('OWPANEL_VERSION', 'dev'),

    // Number of task workers (owpanel-queue@1 to @N, M5-1), 1 to 8. The
    // installer reads the same value from .env when it sets up the units.
    'queue_workers' => max(1, min(8, (int) env('OWPANEL_QUEUE_WORKERS', 3))),

    // Folder of the installed release, releases/<version>/, which holds the
    // archive the panel sends to its servers (server-scripts-update, E-187).
    // Null: the folder above the panel.
    'release_dir' => env('OWPANEL_RELEASE_DIR'),

    /*
    | Public release keys of the project (E-167): release-signers next to the
    | panel code, the same file the installer keeps on every server. The
    | panel shows whether the keys of its servers match.
    */
    'release_signers' => env('OWPANEL_RELEASE_SIGNERS', base_path('../release-signers')),

    /*
    | PHP versions websites may use, like OWP_PHP_VERSIONS in the server
    | scripts (E-172). Older versions are not offered on purpose. New
    | websites get the default unless another one is chosen.
    */
    'php_versions' => ['8.5', '8.6'],
    'php_default' => '8.5',

    /*
    | PHP settings of a website (E-173): what a website gets until someone
    | changes it, and the bounds the server scripts accept (site-create).
    | Packages limit customers further; their fields start with
    | package_defaults. Memory and uploads in MB, run time in seconds.
    */
    'php_settings' => [
        'defaults' => ['memory' => 256, 'upload' => 64, 'time' => 60, 'processes' => 10],
        'min' => ['memory' => 32, 'upload' => 1, 'time' => 10, 'processes' => 1],
        'max' => ['memory' => 4096, 'upload' => 4096, 'time' => 3600, 'processes' => 100],
        'package_defaults' => ['php_memory_mb' => 512, 'php_upload_mb' => 128, 'php_time_s' => 300, 'php_processes' => 10],
    ],

    /*
    | Public repository. The panel always links to the source code of the
    | running version (AGPL-3.0-or-later, section 13; docs/DESIGN.md rule 7).
    | Whoever runs a modified version must point this at their own source.
    */
    'source_url' => env('OWPANEL_SOURCE_URL', 'https://github.com/goettlichit/owpanel'),

    /*
    | Links in the footer (E-073, E-109): the project homepage with its
    | documentation. Empty shows "OWPanel" without a link and points
    | "Documentation" to docs/ in the source code.
    */
    'homepage_url' => env('OWPANEL_HOMEPAGE_URL', 'https://owpanel.org'),
    'vendor_url' => 'https://goettlich.it',

    /*
    | Time zone for times shown in the interface. The database stores UTC
    | (E-046). The setup wizard in milestone 3 will ask for it.
    */
    'timezone' => env('OWPANEL_TIMEZONE', 'Europe/Berlin'),

    /*
    | Languages of the interface (E-002). Every visible text exists in each
    | of them, a test checks that the language files have the same keys.
    */
    'locales' => [
        'de' => 'Deutsch',
        'en' => 'English',
    ],

    /*
    | SSH to the managed servers (E-051). The panel signs in as the agent user
    | with its own Ed25519 key, which `php artisan owpanel:ssh-key` creates.
    | The private key lies outside the web root and is readable only by the
    | user that runs the queue worker (docs/SICHERHEIT.md, "Geheimnisse").
    */
    /*
    | Database tool (E-079): how long a web request waits for the query
    | worker. db-query itself stops after 35 seconds.
    */
    'queries' => [
        'wait_seconds' => 45,
    ],

    /*
    | Database export and import (E-082): directory shared by web requests
    | and the queue worker, and the largest upload.
    */
    'transfers' => [
        'path' => env('OWPANEL_TRANSFER_PATH', storage_path('app/transfers')),
        'max_upload_mb' => 256,
    ],

    'ssh' => [
        'user' => 'owpanel-agent',
        'private_key' => env('OWPANEL_SSH_PRIVATE_KEY', storage_path('app/private/ssh/id_ed25519')),
        // The public half, which a new server gets while it joins (E-196);
        // the installer writes it.
        'public_key' => env('OWPANEL_SSH_PUBLIC_KEY', storage_path('app/panel-ssh.pub')),
        'connect_timeout' => 10,
    ],

];
