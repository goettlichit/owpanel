<?php

// Logs of the panel, the servers and the websites (E-086, E-087).
return [
    'panel_title' => 'Panel-Logs',
    'title' => 'Logs',
    'choose' => 'Log auswählen',
    'panel' => 'Panel',
    'lines_label' => 'Anzahl Zeilen',
    'refresh' => 'Aktualisieren',
    'size' => 'Datei :size',
    'modified' => 'zuletzt',
    'not_active' => 'Die Webseite ist noch nicht eingerichtet; Logs gibt es erst danach.',

    'kinds' => [
        'access' => 'Zugriffe',
        'error' => 'Fehler',
        'php' => 'PHP',
        'auth' => 'Anmeldungen',
        'fail2ban' => 'fail2ban',
        'nginx' => 'nginx',
        'system' => 'System',
        'panel' => 'Panel',
        'worker' => 'Aufgaben-Worker',
        'query' => 'Abfrage-Worker',
        'cron' => 'Cron',
    ],

    'empty' => [
        'access' => 'Noch keine Zugriffe.',
        'error' => 'Keine Fehler. Schön.',
        'php' => 'Keine PHP-Meldungen in den letzten 20.000 Zeilen des Fehlerlogs.',
        'auth' => 'Keine Anmeldungen im Journal.',
        'fail2ban' => 'fail2ban hat noch nichts gemeldet.',
        'nginx' => 'Keine Fehler. Schön.',
        'system' => 'Das Journal ist leer.',
        'panel' => 'Keine Warnungen oder Fehler. Hier stehen nur Meldungen, wenn etwas schiefgeht; was das Panel erledigt hat, steht unter Aufgaben.',
        'worker' => 'Keine Warnungen oder Fehler. Hier stehen nur Probleme des Workers und des Zeitplans; die Aufgaben selbst stehen unter Aufgaben.',
        'query' => 'Keine Einträge. Hier stehen Probleme beim Lesen von Logs und beim Datenbank-Werkzeug sowie jede Push-Zustellung.',
        'cron' => 'Die Cronjobs haben noch nichts ausgegeben.',
    ],

    'hints' => [
        'access' => 'nginx-Zugriffslog der Webseite, neueste Zeile unten. Enthält IP-Adressen der Besucher.',
        'error' => 'nginx-Fehlerlog der Webseite mit den Meldungen von PHP-FPM, neueste Zeile unten.',
        'php' => 'Nur die PHP-Meldungen aus dem Fehlerlog der Webseite (Warnungen, Fehler, Hinweise), neueste Zeile unten.',
        'auth' => 'SSH-Anmeldungen, sudo und su aus dem Journal (auth und authpriv), neueste Zeile unten.',
        'fail2ban' => 'Sperren und Freigaben von fail2ban, neueste Zeile unten.',
        'nginx' => 'Globales nginx-Fehlerlog außerhalb der Webseiten, etwa Konfigurationswarnungen, neueste Zeile unten.',
        'system' => 'Das Journal des Servers mit allen Diensten, neueste Zeile unten.',
        'panel' => 'Warnungen und Fehler der Weboberfläche (storage/logs/laravel.log), neueste Zeile unten. Normale Abläufe werden nicht protokolliert.',
        'worker' => 'Warnungen und Fehler des Workers für Serveraufgaben und des Zeitplans (worker.log), neueste Zeile unten. Die Aufgaben selbst stehen mit ihrem Protokoll unter Aufgaben.',
        'query' => 'Push-Zustellungen sowie Warnungen und Fehler des Abfrage-Workers für Datenbank-Werkzeug und Logs (query.log), neueste Zeile unten.',
        'cron' => 'Ausgaben und Fehler der Cronjobs der Webseite aus dem Journal, neueste Zeile unten.',
    ],

    'errors' => [
        'log_unreadable' => 'Die Logdatei ist für die Weboberfläche nicht lesbar.',
        'server_not_found' => 'Den Server gibt es nicht mehr.',
    ],
];
