<?php

// Mails of the panel (E-094).
return [
    'test' => [
        'subject' => 'Testmail von OWPanel',
        'body' => 'Diese Testmail hat das Panel unter :url verschickt. Der E-Mail-Versand funktioniert.',
    ],
    'reset' => [
        'subject' => 'Passwort zurücksetzen',
        'greeting' => 'Hallo :name,',
        'intro' => 'jemand hat für Ihr Konto im Panel einen Link zum Zurücksetzen des Passworts angefordert.',
        'action' => 'Neues Passwort festlegen',
        'expire' => 'Der Link gilt :count Minuten und nur einmal.',
        'outro' => 'Wenn Sie das nicht waren, müssen Sie nichts tun; Ihr Passwort bleibt unverändert.',
        'salutation' => 'Ihr OWPanel',
    ],

    // Invitation to the customer area (E-128).
    'invitation' => [
        'subject' => 'Ihr Zugang zum Kundenbereich',
        'greeting' => 'Hallo :name,',
        'intro' => 'für Sie wurde ein Zugang zum Kundenbereich eingerichtet. Dort sehen Sie Ihre Webseiten.',
        'email' => 'Sie melden sich mit der E-Mail-Adresse :email an.',
        'action' => 'Passwort festlegen',
        'expire' => 'Der Link gilt :count Stunden und nur einmal.',
        'outro' => 'Wenn Sie mit dieser Nachricht nichts anfangen können, müssen Sie nichts tun.',
    ],
];
