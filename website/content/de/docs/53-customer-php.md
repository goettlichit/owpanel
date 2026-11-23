---
title: PHP
description: PHP-Version wechseln, Arbeitsspeicher, Upload-Größe und Laufzeit einstellen.
audience: customer
icon: brand-php
slug: customer-php
---

## PHP-Version

Ihre Webseite läuft mit PHP 8.5 oder 8.6. Unter **PHP → PHP-Version** wechseln Sie die Version. Die Webseite ist dabei etwa eine Sekunde nicht erreichbar. Cronjobs, die `php` aufrufen, nutzen danach ebenfalls die neue Version.

Prüfen Sie nach dem Wechsel, ob alles wie gewohnt läuft. Gibt es Fehler, stellen Sie einfach zurück. Ob Ihre Anwendung eine Version unterstützt, steht meist in ihren Systemanforderungen.

## PHP-Einstellungen

Unter **PHP → PHP-Einstellungen** stellen Sie ein:

| Einstellung | Bedeutung | Ohne Änderung |
|---|---|---|
| Arbeitsspeicher | so viel darf ein einzelner Aufruf belegen | 256 MB |
| Upload-Größe | so groß darf eine hochgeladene Datei höchstens sein | 64 MB |
| Laufzeit | so lange darf ein Aufruf höchstens laufen | 60 Sekunden |
| Fehler im Browser anzeigen | PHP-Fehler erscheinen auf der Seite | aus |

Die Höchstwerte gibt Ihr Paket vor; die Karte zeigt, was erlaubt ist. Die Werte gelten sofort nach dem Speichern.

::: warning
Schalten Sie **Fehler im Browser anzeigen** nur kurz zur Fehlersuche ein. Fehlermeldungen verraten Besuchern Pfade und manchmal Zugangsdaten. Die Meldungen finden Sie auch ohne diesen Schalter unter **Logs → PHP**.
:::

## Weitere Einstellungen mit .user.ini

Andere PHP-Einstellungen setzen Sie in einer Datei `.user.ini` in `htdocs/public/`, zum Beispiel die Zeitzone:

```ini
date.timezone = Europe/Berlin
```

PHP liest die Datei nach spätestens fünf Minuten neu. Arbeitsspeicher, Upload-Größe und Laufzeit lassen sich so nicht über die Werte aus dem Panel heben.
