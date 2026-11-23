---
title: Cronjobs und Logs
description: Regelmäßige Aufgaben einrichten und Fehler der Webseite finden.
audience: customer
icon: clock-play
slug: customer-cron
---

## Cronjobs

Cronjobs sind Befehle, die regelmäßig laufen, etwa der Zeitplaner einer Anwendung. Unter **Cronjobs** legen Sie einen an, ändern ihn, pausieren ihn oder löschen ihn.

Der Zeitplan hat fünf Felder wie bei crontab: Minute, Stunde, Tag, Monat und Wochentag. Für die häufigsten Fälle gibt es Vorlagen. Einige Beispiele:

| Zeitplan | Bedeutung |
|---|---|
| `* * * * *` | jede Minute |
| `*/15 * * * *` | alle 15 Minuten |
| `0 3 * * *` | täglich um 3 Uhr |
| `0 6 * * 1` | montags um 6 Uhr |

Typische Befehle:

```sh
php artisan schedule:run
php public/wp-cron.php
php scripts/aufraeumen.php
```

Cronjobs laufen im Ordner `htdocs` und mit der PHP-Version Ihrer Webseite. Wie viele Cronjobs Sie haben und wie oft sie höchstens laufen, legt Ihr Paket fest.

## Logs

Unter **Logs** sehen Sie die letzten Einträge Ihrer Webseite, die neueste Zeile unten:

| Reiter | Inhalt |
|---|---|
| Zugriffe | jeder Aufruf mit Adresse, Seite und Antwort |
| Fehler | Fehler des Webservers und von PHP |
| PHP | nur die PHP-Meldungen: Fehler, Warnungen, Hinweise |
| Cron | Ausgaben und Fehler Ihrer Cronjobs |

Zeigt Ihre Webseite eine weiße Seite oder „500“, steht der Grund meist unter **PHP** oder **Fehler**.
