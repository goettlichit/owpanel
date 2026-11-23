<?php

// Customer area (E-124, E-143). Customers are addressed with "Sie" (E-132).
return [
    'sites' => [
        'title' => 'Meine Webseiten',
        'all' => 'Alle Webseiten',
        'empty' => 'Noch keine Webseiten.',
        'empty_hint' => 'Sobald Ihr Anbieter eine Webseite für Sie anlegt, erscheint sie hier.',
        'state' => [
            'busy' => 'Eine Änderung an dieser Webseite läuft gerade. Die Seite zeigt den neuen Stand, sobald sie fertig ist.',
            'pending' => 'Die Webseite wird gerade eingerichtet.',
            'deleting' => 'Die Webseite wird gerade gelöscht.',
            'failed' => 'Die letzte Änderung ist fehlgeschlagen. Bitte versuchen Sie es später erneut oder wenden Sie sich an uns.',
        ],
        'certificate' => [
            'active' => 'Die Webseite ist über https:// erreichbar. Das Zertifikat wird automatisch verlängert.',
            'off' => 'Die Webseite hat noch kein Zertifikat und ist nur über HTTP erreichbar. Bevor Sie eines anfordern, muss die Domain auf den Server zeigen; das prüfen Sie unter DNS.',
            'pending' => 'Das Zertifikat wird angefordert, sobald die Webseite eingerichtet ist.',
            'expiring' => 'Das Zertifikat läuft bald ab. Normalerweise wird es automatisch verlängert; bleibt der Hinweis, wenden Sie sich bitte an uns.',
        ],
        'www' => [
            'no_certificate' => 'Für :www gibt es noch kein Zertifikat. Zeigt die Adresse auf den Server, können Sie es erneut anfordern.',
        ],
    ],

    'dns' => [
        'overall' => [
            'ok' => 'Alle Einträge zeigen auf die richtige Adresse.',
            'waiting' => 'Die Einträge sind richtig, werden aber noch verteilt. Das dauert meist nur wenige Minuten bis Stunden.',
            'missing' => 'Mindestens ein Eintrag fehlt. Bitte legen Sie ihn bei Ihrem DNS-Anbieter mit der Soll-Adresse an.',
            'wrong' => 'Mindestens ein Eintrag zeigt auf eine andere Adresse. Bitte ändern Sie ihn bei Ihrem DNS-Anbieter auf die Soll-Adresse.',
            'unknown' => 'Der Stand ließ sich gerade nicht ermitteln. Bitte prüfen Sie später erneut.',
        ],
        'about' => [
            'Gezeigt werden die Einträge A (IPv4) und AAAA (IPv6) für Ihre Domain und, falls eingeschaltet, für www. Andere Einträge wie MX oder TXT pflegen Sie weiter bei Ihrem DNS-Anbieter.',
            '„Soll“ ist die Adresse, auf die der Eintrag zeigen muss. „Antwort Nameserver“ ist, was Ihr DNS-Anbieter gerade ausliefert, „Antwort Resolver“ das, was Besucher noch aus Zwischenspeichern erhalten können.',
            'Antworten werden fünf Minuten zwischengespeichert. „DNS prüfen“ fragt sofort neu.',
        ],
    ],

    'databases' => [
        'failed' => 'Diese Änderung an der Datenbank ist fehlgeschlagen. Bitte versuchen Sie es später erneut oder wenden Sie sich an uns.',
    ],

    'package' => [
        'none' => 'Für Ihr Konto ist kein Paket gebucht. Neues können Sie erst anlegen, wenn wir Ihnen ein Paket zugeordnet haben.',
        'cron' => 'Ihr Paket: :used von :max Cronjobs, kürzester Abstand :interval.',
        'databases' => 'Ihr Paket: :used von :max Datenbanken.',
    ],

    'backups' => [
        'left' => 'Heute noch :left von :max Sicherungen und Wiederherstellungen.',
        'no_package' => 'Für Ihr Konto ist kein Paket gebucht; eigene Sicherungen und Wiederherstellungen sind deshalb nicht möglich.',
        'used_up' => 'Für heute sind alle :max Sicherungen und Wiederherstellungen Ihres Pakets aufgebraucht. Ab morgen sind wieder neue möglich.',
        'unlimited' => 'Sicherungen und Wiederherstellungen ohne Grenze je Tag.',
    ],

    'limits' => [
        'storage' => 'Der Speicherplatz Ihres Pakets ist aufgebraucht. Geben Sie zuerst Platz frei, etwa durch Löschen alter Dateien oder Datenbanken, oder wenden Sie sich an uns.',
        'cron' => 'Ihr Paket erlaubt :max Cronjobs, und alle sind belegt. Um einen neuen anzulegen, löschen Sie zuerst einen bestehenden oder wenden Sie sich an uns.',
        'database' => 'Ihr Paket erlaubt :max Datenbanken, und alle sind belegt. Um eine neue anzulegen, löschen Sie zuerst eine bestehende oder wenden Sie sich an uns.',
    ],

    'storage' => [
        'title' => 'Speicherplatz',
        'about' => [
            'Dateien und Datenbanken aller Ihrer Webseiten zählen zusammen.',
            'Ist der Speicherplatz voll, können Ihre Webseiten keine Dateien mehr speichern, etwa Uploads, Caches oder Sitzungen. Neue Datenbanken und Importe sind dann nicht möglich.',
            'Gemessen wird alle 15 Minuten.',
        ],
        'warning' => 'Ihr Speicherplatz ist fast voll. Bitte geben Sie Platz frei oder wenden Sie sich an uns.',
        'full' => 'Ihr Speicherplatz ist voll. Ihre Webseiten können keine Dateien mehr speichern. Bitte geben Sie Platz frei oder wenden Sie sich an uns.',
    ],

    'suspension' => [
        'banner' => 'Diese Webseite ist gesperrt.',
        'banner_text' => 'Sie ist für Besucher nicht erreichbar, und Änderungen sind nicht möglich. Bitte wenden Sie sich an uns.',
    ],
];
