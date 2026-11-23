<?php

return [
    'title' => 'Übersicht',
    'pretitle' => 'OWPanel',

    // Left menu of the overview; monitoring and notifications follow later.
    'menu' => [
        'summary' => 'Zusammenfassung',
    ],

    'welcome' => [
        'title' => 'Willkommen',
        'text' => 'Das Panel befindet sich im Aufbau. Hier erscheinen bald Server, Webseiten und laufende Aufgaben.',
    ],

    'stats' => [
        'users' => 'Benutzer',
        'admins_hint' => '{1} davon :count Administrator|[0,*] davon :count Administratoren',
        'sites' => 'Webseiten',
        'sites_hint' => '{1} :tls mit Zertifikat, :count Datenbank|[0,*] :tls mit Zertifikat, :count Datenbanken',
        'failed_logins' => 'Fehlversuche (24 h)',
        'failed_logins_hint' => 'Fehlgeschlagene Anmeldungen',
        'lockouts_hint' => '{1} :count Sperre nach zu vielen Versuchen|[0,*] :count Sperren nach zu vielen Versuchen',
        'check' => 'Prüfen',
        'two_factor' => 'Zwei-Faktor',
        'all_set' => 'Alle aktiv',
        'two_factor_ok' => 'Alle Administratoren nutzen Zwei-Faktor',
        'two_factor_missing' => '{1} :count Administrator ohne Zwei-Faktor|[0,*] :count Administratoren ohne Zwei-Faktor',
        'action_needed' => 'Aktion nötig',
    ],

    'servers' => [
        'title' => 'Server',
        'all_good' => 'Alle Server sind erreichbar und aktuell, alle Zertifikate gültig.',
        'attention' => 'Einige Server brauchen Aufmerksamkeit.',
        'none' => 'Noch kein Server eingetragen.',
        'certificates' => 'Zertifikate',
        'panel_certificate' => 'Zertifikat des Panels',
        'expires' => 'läuft ab am :date',
        'expired' => 'abgelaufen am :date',
        'all' => 'Alle Server',
    ],
];
