<?php

// Logs of the panel, the servers and the websites (E-086, E-087).
return [
    'panel_title' => 'Panel logs',
    'title' => 'Logs',
    'choose' => 'Choose log',
    'panel' => 'Panel',
    'lines_label' => 'Number of lines',
    'refresh' => 'Refresh',
    'size' => 'File :size',
    'modified' => 'last',
    'not_active' => 'The website is not set up yet; logs exist only after that.',

    'kinds' => [
        'access' => 'Access',
        'error' => 'Errors',
        'php' => 'PHP',
        'auth' => 'Sign-ins',
        'fail2ban' => 'fail2ban',
        'nginx' => 'nginx',
        'system' => 'System',
        'panel' => 'Panel',
        'worker' => 'Task worker',
        'query' => 'Query worker',
        'cron' => 'Cron',
    ],

    'empty' => [
        'access' => 'No requests yet.',
        'error' => 'No errors. Nice.',
        'php' => 'No PHP messages in the last 20,000 lines of the error log.',
        'auth' => 'No sign-ins in the journal.',
        'fail2ban' => 'fail2ban has not reported anything yet.',
        'nginx' => 'No errors. Nice.',
        'system' => 'The journal is empty.',
        'panel' => 'No warnings or errors. Only problems show up here; what the panel did is listed under Tasks.',
        'worker' => 'No warnings or errors. Only problems of the worker and the schedule show up here; the tasks themselves are listed under Tasks.',
        'query' => 'No entries. Problems while reading logs and in the database tool show up here, as does every push delivery.',
        'cron' => 'The cron jobs have not printed anything yet.',
    ],

    'hints' => [
        'access' => 'nginx access log of the website, newest line at the bottom. Contains IP addresses of visitors.',
        'error' => 'nginx error log of the website with the messages of PHP-FPM, newest line at the bottom.',
        'php' => 'Only the PHP messages from the error log of the website (warnings, errors, notices), newest line at the bottom.',
        'auth' => 'SSH sign-ins, sudo and su from the journal (auth and authpriv), newest line at the bottom.',
        'fail2ban' => 'Bans and unbans of fail2ban, newest line at the bottom.',
        'nginx' => 'Global nginx error log outside the websites, such as configuration warnings, newest line at the bottom.',
        'system' => 'The journal of the server with all services, newest line at the bottom.',
        'panel' => 'Warnings and errors of the web interface (storage/logs/laravel.log), newest line at the bottom. Normal work is not logged.',
        'worker' => 'Warnings and errors of the worker for server tasks and of the schedule (worker.log), newest line at the bottom. The tasks themselves, with their log, are listed under Tasks.',
        'query' => 'Push deliveries and warnings and errors of the query worker for the database tool and logs (query.log), newest line at the bottom.',
        'cron' => 'Output and errors of the website\'s cron jobs from the journal, newest line at the bottom.',
    ],

    'errors' => [
        'log_unreadable' => 'The log file cannot be read by the web interface.',
        'server_not_found' => 'The server no longer exists.',
    ],
];
