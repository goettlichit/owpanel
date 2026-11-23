<?php

// Packages with limits for a whole customer (E-125).
return [
    'title' => 'Pakete',
    'back' => 'Zurück zu den Paketen',
    'empty' => 'Noch keine Pakete.',
    'empty_hint' => 'Ein Paket legt fest, wie viele Webseiten, Datenbanken und Cronjobs ein Kunde haben darf und wie viel Speicherplatz er bekommt.',
    'unlimited' => 'unbegrenzt',
    'customers_count' => 'Kunden',

    'fields' => [
        'name' => 'Name',
        'name_hint' => 'Etwa „Webspace S“. Kunden sehen den Namen später im Kundenbereich.',
        'max_sites' => 'Webseiten',
        'storage_mb' => 'Speicherplatz',
        'storage_gb' => 'Speicherplatz in GB',
        'storage_hint' => 'Dateien und Datenbanken aller Webseiten zusammen, etwa 5 oder 0,5. Durchgesetzt wird er mit der Quota in einem späteren Schritt.',
        'max_databases' => 'Datenbanken',
        'max_cron_jobs' => 'Cronjobs',
        'min_cron_interval' => 'Kürzester Abstand der Cronjobs',
        'min_cron_interval_short' => 'Cron-Abstand',
        'min_cron_interval_hint' => 'In Minuten. 5 heißt: höchstens alle 5 Minuten.',
        'daily_self_service' => 'Sicherungen und Wiederherstellungen je Tag',
        'daily_self_service_short' => 'Sicherungen je Tag',
        'daily_self_service_hint' => 'Die der Kunde selbst startet. Die automatischen Sicherungen zählen nicht mit.',
        'php_memory_mb' => 'Arbeitsspeicher je Aufruf',
        'php_memory_mb_hint' => 'Höchstwert für memory_limit, den eine Webseite wählen kann. Leer: bis :max MB.',
        'php_upload_mb' => 'Größte Upload-Datei',
        'php_upload_mb_hint' => 'Höchstwert für Uploads, auch die Grenze von nginx. Leer: bis :max MB.',
        'php_time_s' => 'Längste Laufzeit',
        'php_time_s_hint' => 'Höchstwert für die Laufzeit eines Aufrufs in Sekunden. Leer: bis :max s.',
        'php_processes' => 'PHP-Prozesse je Webseite',
        'php_processes_hint' => 'So viele Aufrufe einer Webseite laufen gleichzeitig; der Arbeitsspeicher einer Webseite ist höchstens Prozesse × Arbeitsspeicher. Leer: bis :max.',
        'empty_is_unlimited' => 'Leer lassen für unbegrenzt.',
    ],

    'php' => [
        'title' => 'PHP',
        'text' => 'Grenzen für jede Webseite des Kunden. Er wählt die Werte bei seiner Webseite selbst, höchstens bis hierhin. Eine Änderung gilt sofort für alle Webseiten der Kunden mit diesem Paket.',
    ],

    'units' => [
        'seconds' => ':count s',
        'megabytes' => ':count MB',
        'minutes' => ':count Min.',
    ],

    'create' => [
        'title' => 'Paket anlegen',
        'form' => 'Neues Paket',
        'submit' => 'Anlegen',
    ],

    'edit' => [
        'form' => 'Grenzen',
        'submit' => 'Speichern',
    ],

    'about' => [
        'title' => 'So wirken Pakete',
        'points' => [
            'Ein Paket gilt für einen ganzen Kunden: Die Grenzen zählen über alle seine Webseiten.',
            'Eine Änderung gilt sofort für alle Kunden mit diesem Paket.',
            'Ist eine Grenze erreicht, kann der Kunde nichts Neues anlegen. Gelöscht wird nie etwas, auch nicht nach einem kleineren Paket.',
            'Grenzen gelten nur für Webseiten, die einem Kunden zugeordnet sind. Webseiten der Administration haben keine.',
            'Administratoren dürfen nach einer Bestätigung über die Grenzen gehen; das steht im Audit-Log und wird beim Kunden angezeigt.',
            'Ein Kunde ohne Paket kann selbst nichts anlegen.',
            'Preise und Verträge bleiben im externen System.',
        ],
    ],

    'customers' => [
        'title' => 'Kunden mit diesem Paket',
        'empty' => 'Noch kein Kunde hat dieses Paket.',
    ],

    'delete' => [
        'title' => 'Paket löschen',
        'text' => 'Das Paket wird entfernt. Kunden haben es nicht, deshalb ändert sich für niemanden etwas.',
        'submit' => 'Paket löschen',
        'in_use' => 'Ein Paket, das Kunden haben, lässt sich nicht löschen. Ordne den Kunden zuerst ein anderes Paket zu.',
    ],

    'toast' => [
        'created' => 'Das Paket :name ist angelegt.',
        'updated' => 'Das Paket ist gespeichert. Die Grenzen gelten ab sofort für alle Kunden damit.',
        'deleted' => 'Das Paket ist gelöscht.',
    ],

    // Limits broken by an action (E-125, E-139).
    'limit_broken' => [
        'storage_mb' => 'Der Speicherplatz des Pakets (:max) ist aufgebraucht.',
        'max_sites' => 'Das Paket erlaubt höchstens :max Webseiten.',
        'max_databases' => 'Das Paket erlaubt höchstens :max Datenbanken.',
        'max_cron_jobs' => 'Das Paket erlaubt höchstens :max Cronjobs.',
        'min_cron_interval' => 'Das Paket verlangt mindestens :interval Abstand zwischen zwei Läufen eines Cronjobs.',
        'daily_self_service' => 'Ihr Paket erlaubt :max Sicherungen und Wiederherstellungen je Tag; für heute sind sie aufgebraucht.',
        'no_package' => 'Für Ihr Konto ist kein Paket gebucht. Bitte wenden Sie sich an uns.',
        'cron_locked' => 'Dieser Cronjob liegt über der Grenze Ihres Pakets und lässt sich nur noch löschen.',
    ],

    // Confirmation for administrators (E-139).
    'confirm' => [
        'title' => 'Grenze des Pakets überschritten',
        'text' => 'Als Administrator darfst du trotzdem speichern. Das wird im Audit-Log festgehalten; der Kunde kann einen solchen Cronjob danach nur noch löschen.',
        'submit' => 'Trotzdem speichern',
        'cancel' => 'Abbrechen',
    ],

    // Notes on the pages of a website (E-139).
    'note' => [
        'unassigned' => 'Die Webseite ist nicht zugeordnet, es ist kein Paket gebucht.',
        'assign' => 'Zuordnen',
        'no_package' => 'Für den Kunden ist kein Paket gebucht.',
        'choose' => 'Paket wählen',
        'cron' => 'Paket :name: :used von :max Cronjobs, kürzester Abstand :interval.',
        'databases' => 'Paket :name: :used von :max Datenbanken.',
        'over' => 'über der Paketgrenze',
    ],
];
