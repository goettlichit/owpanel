<?php

// Server -> Backups: the websites of a server and their backups (E-096).
return [
    'title' => 'Backups',
    'sites' => 'Webseiten auf diesem Server',
    'site' => 'Webseite',
    'daily' => 'Automatische Sicherung',
    'last' => 'Letzte Sicherung',
    'never' => 'noch keine',
    'open' => 'Sicherungen',
    'no_sites' => 'Auf diesem Server gibt es noch keine Webseiten.',
    'missing' => [
        'title' => 'Fehlende Webseiten',
        'badge' => 'fehlt auf dem Server',
        'text' => 'Diese Webseiten kennt das Panel, auf dem Server fehlen sie aber, etwa nach einer Wiederherstellung des Panels. Der Neuaufbau legt jede mit ihren Datenbanken neu an und spielt ihre neueste Sicherung ein; danach folgen Cronjobs, SFTP und das Zertifikat. Vorher setzt er die eigenen Firewall-Regeln und fail2ban-Ausnahmen des Servers wieder.',
        'confirm' => 'Ich möchte diese Webseiten auf dem Server neu anlegen.',
        'submit' => 'Aus Sicherungen neu aufbauen',
        'not_ready' => 'Die Webseiten lassen sich neu aufbauen, sobald das Backup-Ziel verbunden ist.',
        'started' => 'Neuaufbau gestartet: :sites.',
        'none' => 'Auf dem Server fehlt keine Webseite.',
    ],
    'backup_all' => [
        'started' => 'Sicherung von :count Webseiten gestartet.',
        'started_panel' => 'Sicherung von :count Webseiten und des Panels gestartet.',
        'skipped' => ':skipped übersprungen, weil dort gerade eine Aufgabe läuft oder die Webseite auf dem Server fehlt.',
        'none' => 'Nichts ließ sich sichern: Überall läuft gerade eine Aufgabe, oder die Webseiten fehlen auf dem Server.',
    ],
    'rules_title' => 'So wird gesichert',
    'rules' => [
        'daily' => 'Jeden Tag ab :time Uhr sichert der Server alle Webseiten, bei denen die automatische Sicherung an ist.',
        'interval' => 'Ab :time Uhr alle :hours Stunden sichert der Server alle Webseiten, bei denen die automatische Sicherung an ist.',
        'retention_hourly' => 'Aufbewahrt wird die neueste je Stunde für :hourly Stunden, je Tag für :daily Tage, je Woche für :weekly Wochen und je Monat für :monthly Monate.',
        'retention' => 'Aufbewahrt wird die neueste je Tag für :daily Tage, je Woche für :weekly Wochen und je Monat für :monthly Monate.',
        'where' => 'Die Sicherungen einer Webseite, ihren Inhalt und die Wiederherstellung findest du bei der Webseite unter „Backups“.',
        'now' => '„Jetzt sichern“ oben sichert sofort alle Webseiten dieses Servers von Hand, jede als eigene Aufgabe, und auf dem Server des Panels auch das Panel; solche Sicherungen bleiben, bis jemand sie löscht.',
        'own' => 'Jeder Server sichert in sein eigenes, verschlüsseltes Repository auf der Storage Box.',
    ],
    'panel_files' => [
        'database' => 'Datenbank des Panels',
        'env' => '.env mit App-Schlüssel und Datenbank-Passwort',
        'state' => 'Einstellungen des Installers',
        'key' => 'SSH-Schlüssel des Panels',
        'public_key' => 'Öffentlicher SSH-Schlüssel des Panels',
        'known_hosts' => 'Bekannte Host-Schlüssel',
    ],
    'panel_show' => [
        'title' => 'Panel-Sicherung :id',
        'back' => 'Alle Backups des Servers',
        'restore_title' => 'Wiederherstellen',
        'restore' => [
            'why' => 'Eine Sicherung des Panels lässt sich nicht im laufenden Panel einspielen: Es würde seine eigene Datenbank und Schlüssel im Betrieb überschreiben.',
            'how' => 'Wiederhergestellt wird auf einem neuen Server: get-owpanel fragt bei der ersten Installation „Restore from a backup?“, lässt eine der letzten zehn Sicherungen wählen und baut auf Wunsch auch die Webseiten des Servers neu auf. Die Anleitung steht in docs/WIEDERHERSTELLUNG.md.',
            'data' => 'Dafür brauchst du Subaccount und Repository-Passwort dieses Servers aus den Notfalldaten unter System → Backup.',
        ],
    ],
];
