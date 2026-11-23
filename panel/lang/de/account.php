<?php

return [
    'title' => 'Mein Konto',

    'roles' => [
        'admin' => 'Administrator',
        'customer' => 'Kunde',
    ],

    'profile' => [
        'details_title' => 'Persönliche Daten',
        'email_hint' => 'Die E-Mail-Adresse ist Ihr Benutzername. Ändern kann sie ein Administrator.',
        'save' => 'Name speichern',
        'title' => 'Profil',
        'role' => 'Rolle',
        'last_login' => 'Letzte Anmeldung',
    ],

    'security' => [
        'title' => 'Anmeldung und Sicherheit',
        'description' => 'Wie Sie sich am Panel anmelden. Ihr Passwort bleibt immer der Grundweg.',
        'oidc_settings' => 'Einstellungen für Pocket ID',
    ],

    'password' => [
        'title' => 'Passwort ändern',
        'current_password' => 'Aktuelles Passwort',
        'password' => 'Neues Passwort',
        'password_confirmation' => 'Neues Passwort wiederholen',
        'submit' => 'Passwort ändern',
        'change_required' => 'Bitte ändern Sie zuerst Ihr Start-Passwort. Als aktuelles Passwort gilt das Passwort, das Sie erhalten haben.',
    ],

    'two_factor' => [
        'enabled_since' => 'Aktiv seit :date.',
        'required' => 'Administratoren müssen die Zwei-Faktor-Authentifizierung einrichten, bevor sie das Panel nutzen können.',
        'required_for_admins' => 'Für Administratoren ist sie Pflicht.',
        'admin_cannot_disable' => 'Administratoren können die Zwei-Faktor-Authentifizierung nicht abschalten.',
        'setup_title' => 'Zwei-Faktor-Authentifizierung einrichten',
        'setup_intro' => 'Bei der Anmeldung fragt das Panel zusätzlich zum Passwort nach einem Code aus einer Authenticator-App, zum Beispiel 2FAS, Aegis oder der App Ihres Passwortmanagers.',
        'start' => 'Einrichtung starten',
        'confirm_title' => 'Zwei-Faktor-Authentifizierung: App verbinden',
        'scan' => 'Scannen Sie den QR-Code mit Ihrer Authenticator-App und geben Sie danach den angezeigten Code ein.',
        'manual_key' => 'Oder geben Sie diesen Schlüssel von Hand ein:',
        'confirm' => 'Code bestätigen',
        'active_title' => 'Zwei-Faktor-Authentifizierung',
        'recovery_title' => 'Wiederherstellungscodes',
        'recovery_intro' => 'Bewahren Sie diese Codes sicher auf, etwa in Ihrem Passwortmanager. Jeder Code funktioniert einmal, falls Sie keinen Zugriff auf die App haben. Die Codes werden nur jetzt angezeigt.',
        'regenerate' => 'Neue Wiederherstellungscodes erzeugen',
        'disable' => 'Abschalten',
    ],

    // Messages after Fortify actions, keyed by Fortify's status codes.
    'status' => [
        'profile-information-updated' => 'Name gespeichert.',
        'password-updated' => 'Das Passwort wurde geändert.',
        'two-factor-authentication-enabled' => 'Scannen Sie jetzt den QR-Code und bestätigen Sie ihn mit einem Code.',
        'two-factor-authentication-confirmed' => 'Die Zwei-Faktor-Authentifizierung ist aktiv.',
        'two-factor-authentication-disabled' => 'Die Zwei-Faktor-Authentifizierung ist abgeschaltet.',
        'recovery-codes-generated' => 'Neue Wiederherstellungscodes wurden erzeugt, die alten gelten nicht mehr.',
    ],
];
