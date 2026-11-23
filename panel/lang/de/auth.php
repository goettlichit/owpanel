<?php

return [
    // Locked account (E-127); shown only after a correct password.
    'locked' => 'Ihr Zugang ist gesperrt. Bitte wenden Sie sich an Ihren Anbieter.',

    // Invitation of a customer (E-128).
    'invitation' => [
        'title' => 'Zugang einrichten',
        'intro' => 'Legen Sie ein Passwort fest. Danach melden Sie sich mit Ihrer E-Mail-Adresse und diesem Passwort an.',
        'invalid' => 'Dieser Link ist ungültig, schon benutzt oder abgelaufen. Bitte fordern Sie bei Ihrem Anbieter eine neue Einladung an.',
        'to_login' => 'Zur Anmeldung',
        'submit' => 'Passwort festlegen',
        'done' => 'Ihr Passwort ist festgelegt. Sie können sich jetzt anmelden.',
    ],

    // Used by Laravel and Fortify.
    'failed' => 'Diese Zugangsdaten sind nicht bekannt.',
    'password' => 'Das Passwort ist falsch.',
    'throttle' => 'Zu viele Anmeldeversuche. Bitte in :seconds Sekunden erneut versuchen.',

    'fields' => [
        'name' => 'Name',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
    ],

    'login' => [
        'title' => 'Anmelden',
        'remember' => 'Angemeldet bleiben',
        'submit' => 'Anmelden',
    ],

    'oidc' => [
        'login' => 'Anmelden mit :name',
        'or' => 'oder',
        'title' => 'Anmeldung mit :name',
        'intro' => 'Verknüpfen Sie Ihr Konto mit :name, dann melden Sie sich dort mit Ihrem Passkey an. Passwort und Zwei-Faktor bleiben daneben erhalten.',
        'linked_as' => 'Verknüpft mit :account',
        'linked_since' => 'seit :date',
        'last_login' => 'zuletzt angemeldet :date',
        'never' => 'noch nicht darüber angemeldet',
        'not_set_up' => 'Die Anmeldung mit :name ist noch nicht eingerichtet.',
        'not_linked' => 'Noch nicht verknüpft.',
        'link' => 'Mit :name verknüpfen',
        'unlink' => 'Verknüpfung trennen',
        'other_issuer' => 'Die Verknüpfung gehört zu einer früheren Adresse und gilt nicht mehr. Trennen Sie sie und verknüpfen Sie neu.',
        'linked' => 'Ihr Konto ist jetzt mit :name verknüpft.',
        'unlinked' => 'Die Verknüpfung mit :name ist getrennt.',
        'errors' => [
            'not_configured' => 'Die Anmeldung mit :name ist nicht eingerichtet.',
            'connection_failed' => ':name ist gerade nicht erreichbar. Bitte mit Passwort anmelden.',
            'expired' => 'Die Anmeldung hat zu lange gedauert oder wurde neu gestartet. Bitte noch einmal.',
            'cancelled' => 'Die Anmeldung bei :name wurde abgebrochen.',
            'not_linked' => 'Dieses Konto bei :name ist mit keinem Panel-Konto verknüpft. Melden Sie sich mit Passwort an und verknüpfen Sie es unter Mein Konto → Anmeldung und Sicherheit.',
            'not_allowed' => 'Die Anmeldung mit :name ist für Ihr Konto nicht freigeschaltet. Bitte mit Passwort anmelden.',
            'taken' => 'Dieses Konto bei :name ist schon mit einem anderen Panel-Konto verknüpft. Zum Verknüpfen bei :name mit dem passenden Konto anmelden, bei Bedarf vorher dort abmelden.',
            'taken_account' => 'Das Konto :account bei :name ist schon mit einem anderen Panel-Konto verknüpft. Zum Verknüpfen bei :name mit dem passenden Konto anmelden, bei Bedarf vorher dort abmelden oder einen anderen Passkey wählen.',
            'failed' => 'Die Anmeldung mit :name ist fehlgeschlagen. Bitte noch einmal oder mit Passwort anmelden.',
        ],
    ],

    'forgot' => [
        'link' => 'Passwort vergessen?',
        'title' => 'Passwort vergessen',
        'intro' => 'Geben Sie die E-Mail-Adresse Ihres Kontos ein. Wir schicken Ihnen einen Link, mit dem Sie ein neues Passwort festlegen.',
        'submit' => 'Link anfordern',
        'back' => 'Zurück zur Anmeldung',
        'sent' => 'Wenn es ein Konto mit dieser Adresse gibt, ist der Link unterwegs. Sehen Sie auch im Spam-Ordner nach.',
        'throttled' => 'Zu viele Anfragen. Bitte versuchen Sie es in ein paar Minuten noch einmal.',
    ],

    'reset' => [
        'title' => 'Neues Passwort',
        'intro' => 'Legen Sie ein neues Passwort für Ihr Konto fest. Die Zwei-Faktor-Bestätigung bleibt eingeschaltet.',
        'password' => 'Neues Passwort',
        'confirmation' => 'Neues Passwort wiederholen',
        'hint' => 'Mindestens 12 Zeichen.',
        'submit' => 'Passwort speichern',
    ],

    'two_factor' => [
        'title' => 'Zwei-Faktor-Bestätigung',
        'intro' => 'Geben Sie den sechsstelligen Code aus Ihrer Authenticator-App ein.',
        'code' => 'Code',
        'submit' => 'Bestätigen',
        'use_recovery_code' => 'Kein Zugriff auf die App? Wiederherstellungscode verwenden',
        'recovery_code' => 'Wiederherstellungscode',
        'submit_recovery' => 'Mit Wiederherstellungscode anmelden',
    ],

    'confirm' => [
        'title' => 'Passwort bestätigen',
        'intro' => 'Bitte bestätigen Sie Ihr Passwort, bevor Sie fortfahren.',
        'submit' => 'Bestätigen',
    ],
];
