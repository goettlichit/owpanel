<?php

// Panel accounts (E-081).
return [
    'title' => 'Benutzer',

    'fields' => [
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'role' => 'Rolle',
        'two_factor' => 'Zwei-Faktor',
        'last_login' => 'Letzte Anmeldung',
        'actions' => 'Aktionen',
    ],

    'roles' => [
        'admin' => 'Administrator',
        'customer' => 'Kunde',
    ],

    'list' => [
        'title' => 'Konten im Panel',
        'hint' => 'Kundenkonten kommen mit dem Kundenbereich.',
        'you' => 'Du',
        'two_factor_on' => 'aktiv',
        'two_factor_off' => 'nicht eingerichtet',
        'not_signed_in' => 'noch nicht angemeldet',
    ],

    'oidc' => [
        'customer_access' => 'Beim Kunden verwalten',
        'state_linked' => 'verknüpft',
        'state_always' => 'erlaubt',
        'state_allowed' => 'freigeschaltet',
        'state_off' => 'aus',
        'allow' => 'Freischalten',
        'revoke' => 'Sperren',
        'allowed' => ':name für :email freigeschaltet. Der Kunde verknüpft sein Konto einmal selbst.',
        'revoked' => ':name für :email gesperrt; eine bestehende Verknüpfung wurde entfernt.',
    ],

    'create' => [
        'title' => 'Administrator hinzufügen',
        'form' => 'Neuer Administrator',
        'submit' => 'Anlegen',
        'role_hint' => 'Administratoren dürfen im Panel alles. Kundenkonten mit eingeschränkten Rechten folgen mit dem Kundenbereich.',
        'about_title' => 'So geht es weiter',
        'steps' => [
            'Das Panel erzeugt ein Start-Passwort und zeigt es dir einmal an.',
            'Gib E-Mail-Adresse und Start-Passwort auf sicherem Weg weiter.',
            'Bei der ersten Anmeldung muss das Passwort geändert werden.',
            'Danach richtet der neue Administrator die Zwei-Faktor-Authentifizierung ein; vorher ist nichts anderes möglich.',
        ],
    ],

    'created' => [
        'title' => ':name ist angelegt',
        'text' => 'Gib diese Zugangsdaten auf sicherem Weg weiter. Das Passwort muss bei der ersten Anmeldung geändert werden.',
        'password' => 'Start-Passwort',
        'once' => 'Das Start-Passwort wird nur jetzt angezeigt und nirgends gespeichert.',
    ],

    'delete' => [
        'button' => 'Löschen',
        'confirm' => 'Zum Bestätigen :email eingeben',
        'submit' => 'Endgültig löschen',
        'mismatch' => 'Die E-Mail-Adresse stimmt nicht überein.',
        'done' => 'Das Konto :email ist gelöscht.',
    ],
];
