<?php

// SSH login of a server (E-155), administrators only.
return [
    'title' => 'SSH',
    'card' => 'Passwort-Anmeldung',
    'state_on' => 'Administratoren können sich per SSH mit Passwort anmelden.',
    'state_off' => 'Administratoren melden sich per SSH nur mit Schlüssel an. SFTP-Kunden behalten ihr Passwort.',
    'state_on_badge' => 'erlaubt',
    'state_off_badge' => 'abgeschaltet',
    'foreign' => 'Abgeschaltet durch eine Einstellung außerhalb des Panels. Wieder erlauben lässt sie sich nur dort.',
    'account' => 'Konto',
    'key' => 'SSH-Schlüssel',
    'key_yes' => 'vorhanden',
    'key_no' => 'fehlt',
    'missing' => 'Solange ein Konto keinen Schlüssel hat, lässt sich die Passwort-Anmeldung nicht abschalten: Du würdest dich aussperren. Hinterlege zuerst einen Schlüssel in ~/.ssh/authorized_keys.',
    'turn_off' => 'Passwort-Anmeldung abschalten',
    'turn_on' => 'Passwort-Anmeldung wieder erlauben',
    'turning_off' => 'Wird abgeschaltet …',
    'turning_on' => 'Wird erlaubt …',
    'busy' => 'Für diesen Server läuft gerade eine Änderung an SSH. Bitte warte, bis sie fertig ist.',
    'unavailable' => 'Der Zustand ließ sich nicht abfragen: :error',
    'last_failed' => 'Die letzte Änderung ist fehlgeschlagen.',
    'sftp_title' => 'SFTP und AllowUsers/AllowGroups',
    'sftp_ok' => 'sshd beschränkt die Anmeldung nicht, oder die Gruppe owpanel-sftp ist erlaubt.',
    'sftp_blocked' => 'sshd erlaubt nur bestimmte Benutzer oder Gruppen, und owpanel-sftp ist nicht dabei. SFTP-Kunden kommen so nicht herein. Ergänze in der sshd-Konfiguration „AllowGroups … owpanel-sftp“.',
    'about' => [
        'Ohne Passwort-Anmeldung kommen Angreifer mit erratenen Passwörtern nicht mehr herein. Administratoren melden sich dann nur mit SSH-Schlüssel an.',
        'SFTP-Kunden behalten ihr Passwort, weil für die Gruppe owpanel-sftp eine eigene Regel gilt.',
        'Das Panel schaltet nur ab, wenn root und jedes andere Konto mit Anmeldung einen Schlüssel hat. Die Konfiguration wird vor dem Laden mit sshd -t geprüft.',
        'Die Grundeinrichtung ändert diese Einstellung nicht.',
    ],
];
