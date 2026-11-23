<?php

// Server -> Backups: the websites of a server and their backups (E-096).
return [
    'title' => 'Backups',
    'sites' => 'Websites on this server',
    'site' => 'Website',
    'daily' => 'Automatic backup',
    'last' => 'Last backup',
    'never' => 'none yet',
    'open' => 'Backups',
    'no_sites' => 'There are no websites on this server yet.',
    'missing' => [
        'title' => 'Missing websites',
        'badge' => 'missing on the server',
        'text' => 'The panel knows these websites, but the server lacks them, e.g. after the panel was restored. The rebuild creates each with its databases and restores its newest backup; cron jobs, SFTP and the certificate follow. First it puts back the server’s own firewall rules and fail2ban exceptions.',
        'confirm' => 'I want to create these websites on the server again.',
        'submit' => 'Rebuild from backups',
        'not_ready' => 'The websites can be rebuilt once the backup target is connected.',
        'started' => 'Rebuild started: :sites.',
        'none' => 'No website is missing on the server.',
    ],
    'backup_all' => [
        'started' => 'Backup of :count websites started.',
        'started_panel' => 'Backup of :count websites and the panel started.',
        'skipped' => ':skipped skipped because a task is running there or the website is missing on the server.',
        'none' => 'Nothing could be backed up: a task is running everywhere, or the websites are missing on the server.',
    ],
    'rules_title' => 'How backups work',
    'rules' => [
        'daily' => 'Every day from :time the server backs up all websites whose automatic backup is on.',
        'interval' => 'From :time on, every :hours hours the server backs up all websites whose automatic backup is on.',
        'retention_hourly' => 'Kept are the newest per hour for :hourly hours, per day for :daily days, per week for :weekly weeks and per month for :monthly months.',
        'retention' => 'Kept are the newest per day for :daily days, per week for :weekly weeks and per month for :monthly months.',
        'where' => 'A website\'s backups, their content and restoring them are on the website under "Backups".',
        'now' => '“Back up now” at the top backs up all websites of this server by hand right away, each as a task of its own, and on the panel’s server the panel too; such backups stay until someone deletes them.',
        'own' => 'Each server backs up into its own encrypted repository on the Storage Box.',
    ],
    'panel_files' => [
        'database' => 'Database of the panel',
        'env' => '.env with app key and database password',
        'state' => 'Installer settings',
        'key' => 'SSH key of the panel',
        'public_key' => 'Public SSH key of the panel',
        'known_hosts' => 'Known host keys',
    ],
    'panel_show' => [
        'title' => 'Panel backup :id',
        'back' => 'All backups of the server',
        'restore_title' => 'Restoring',
        'restore' => [
            'why' => 'A backup of the panel cannot be restored into the running panel: it would overwrite its own database and keys while running.',
            'how' => 'It is restored on a new server: get-owpanel asks "Restore from a backup?" on a first installation, offers the last ten backups and can rebuild the websites of the server as well. The guide is docs/WIEDERHERSTELLUNG.md.',
            'data' => 'You need the subaccount and repository password of this server from the emergency data under System → Backup.',
        ],
    ],
];
