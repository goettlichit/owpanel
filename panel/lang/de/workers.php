<?php

// Workers and scheduler of the panel under System -> Services (E-133).
return [
    'title' => 'Dienste',
    'names' => [
        'default' => 'Auftrags-Worker',
        'queries' => 'Abfrage-Worker',
        'scheduler' => 'Zeitplaner',
    ],
    'descriptions' => [
        'default' => 'Führt alle Serveraktionen aus: Webseiten anlegen, Sicherungen, Zertifikate, Updates und alles andere, was als Aufgabe erscheint. Mehrere Prozesse arbeiten für verschiedene Server gleichzeitig; auf einem Server läuft immer nur eine Aufgabe.',
        'queries' => 'Liest für das Datenbank-Werkzeug, die Logs, Sicherungen und fail2ban vom Server, damit Seitenaufrufe sich nie selbst mit Servern verbinden.',
        'scheduler' => 'Startet jede Minute die geplanten Arbeiten: Serverstatus, Sicherungen, Snapshots, Benachrichtigungen und die Prüfaufträge für diese Seite.',
    ],
    'states' => [
        'running' => 'läuft',
        'busy' => 'arbeitet',
        'paused' => 'angehalten',
        'unresponsive' => 'reagiert nicht',
        'unknown' => 'noch keine Rückmeldung',
    ],
    'fields' => [
        'processes' => 'Prozesse',
        'unit' => 'systemd-Dienst',
        'last_beat' => 'Zuletzt geantwortet',
        'last_run' => 'Zuletzt gelaufen',
        'current' => 'Gerade in Arbeit',
        'waiting' => 'Wartende Aufträge',
        'failed' => 'Fehlgeschlagene Aufträge',
    ],
    'more_running' => 'und :count weitere',
    'never' => 'noch nie',
    'idle' => 'nichts',
    'since' => 'seit :time',
    'oldest' => '(ältester :time)',
    'last_failed' => 'zuletzt :time',
    'pause' => 'Anhalten',
    'resume' => 'Fortsetzen',
    'banner' => 'Angehalten: :names. Aufträge dort warten, bis du sie fortsetzt.',
    'banner_open' => 'Zu den Diensten',

    'restart' => [
        'title' => 'Worker neu starten',
        'text' => 'Beide Worker beenden ihren laufenden Auftrag und starten neu, etwa nach einem Update oder wenn einer hängt. Wartende Aufträge gehen nicht verloren.',
        'last' => 'Zuletzt angefordert:',
        'submit' => 'Neu starten',
    ],

    'about' => [
        'title' => 'So funktioniert die Anzeige',
        'points' => [
            'Der Zeitplaner legt jede Minute einen kleinen Prüfauftrag in jede Warteschlange. Antwortet ein Worker darauf, läuft er und nimmt Aufträge an.',
            'Ohne Antwort seit über zwei Minuten steht „reagiert nicht“; arbeitet ein Worker gerade an einem langen Auftrag, steht „arbeitet“.',
            '„Anhalten“ ist für Tests gedacht: Der Worker läuft weiter, nimmt aber keine neuen Aufträge an, bis du ihn fortsetzt. Solange zeigt jede Seite einen Hinweis.',
            'Ist der Abfrage-Worker angehalten, zeigen Datenbank-Werkzeug, Logs und Sicherungen nichts an.',
            'Ganz stoppen lässt sich ein Worker hier nicht, denn dann könnte das Panel ihn nicht wieder starten. Das geht per SSH mit systemctl.',
        ],
    ],

    'toast' => [
        'restarted' => 'Neustart angefordert. Die Worker starten neu, sobald ihr laufender Auftrag fertig ist.',
        'paused' => ':name ist angehalten.',
        'resumed' => ':name nimmt wieder Aufträge an.',
    ],
];
