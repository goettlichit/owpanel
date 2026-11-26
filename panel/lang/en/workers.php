<?php

// Workers and scheduler of the panel under System -> Services (E-133).
return [
    'title' => 'Services',
    'names' => [
        'default' => 'Task worker',
        'queries' => 'Query worker',
        'scheduler' => 'Scheduler',
    ],
    'descriptions' => [
        'default' => 'Runs all server actions: creating websites, backups, certificates, updates and everything else that shows up as a task. Several processes work for different servers at the same time; each server runs one task at a time.',
        'queries' => 'Reads from the server for the database tool, logs, backups and fail2ban, so that page requests never connect to servers themselves.',
        'scheduler' => 'Starts the planned work every minute: server state, backups, snapshots, notifications and the check jobs for this page.',
    ],
    'states' => [
        'running' => 'running',
        'busy' => 'working',
        'paused' => 'paused',
        'unresponsive' => 'not responding',
        'unknown' => 'no answer yet',
    ],
    'fields' => [
        'processes' => 'Processes',
        'unit' => 'systemd service',
        'last_beat' => 'Last answered',
        'last_run' => 'Last run',
        'current' => 'Working on',
        'waiting' => 'Waiting jobs',
        'failed' => 'Failed jobs',
    ],
    'more_running' => 'and :count more',
    'never' => 'never',
    'idle' => 'nothing',
    'since' => 'since :time',
    'oldest' => '(oldest :time)',
    'last_failed' => 'last :time',
    'pause' => 'Pause',
    'resume' => 'Resume',
    'banner' => 'Paused: :names. Jobs there wait until you resume them.',
    'banner_open' => 'To the services',

    'restart' => [
        'title' => 'Restart workers',
        'text' => 'Both workers finish their current job and start again, for example after an update or when one is stuck. Waiting jobs are not lost.',
        'last' => 'Last requested:',
        'submit' => 'Restart',
    ],

    'about' => [
        'title' => 'How this page knows',
        'points' => [
            'Every minute the scheduler puts a small check job into each queue. When a worker answers it, it runs and takes jobs.',
            'Without an answer for more than two minutes it shows “not responding”; while a worker works on a long job it shows “working”.',
            '“Pause” is meant for tests: the worker keeps running but takes no new jobs until you resume it. Meanwhile every page shows a notice.',
            'While the query worker is paused, the database tool, logs and backups show nothing.',
            'A worker cannot be stopped completely here, because the panel could not start it again. Use systemctl over SSH for that.',
        ],
    ],

    'toast' => [
        'restarted' => 'Restart requested. The workers start again as soon as their current job is done.',
        'paused' => ':name is paused.',
        'resumed' => ':name takes jobs again.',
    ],
];
