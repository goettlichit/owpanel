---
title: Benachrichtigungen
description: Meldungen per E-Mail und Push, Ruhezeiten und Geräte.
group: Mehr
icon: bell-ringing
slug: notifications
---

Das Panel meldet sich per E-Mail und als Push-Nachricht auf Handy oder Desktop, wenn etwas deine Aufmerksamkeit braucht. Was du bekommst, legst du unter **Mein Konto → Benachrichtigungen** je Anlass und Weg selbst fest.

## Anlässe

**Probleme**, einmal gemeldet und mit Entwarnung, sobald es wieder passt:

- Backup oder Snapshot fehlgeschlagen
- Server nicht erreichbar (seit drei Minuten)
- Neustart nötig, etwa nach einem neuen Kernel
- Festplatte fast voll (ab 90 %, Entwarnung unter 85 %)
- Zertifikat einer Webseite oder des Panels läuft bald ab, weil die automatische Verlängerung seit Tagen scheitert
- Speicherplatz eines Kunden aufgebraucht
- Updates warten seit mehr als einem Tag, wurden also nachts nicht automatisch eingespielt; die Meldung endet still, sobald sie eingespielt sind, denn dafür gibt es „Updates eingespielt“
- Automatische Updates auf einem Server ausgeschaltet
- ein Dienst läuft nicht: nginx, PHP-FPM, MariaDB, fail2ban oder nftables, sofern er auf dem Server eingeschaltet ist
- Storage Box der Backups fast voll (ab 90 %, Entwarnung unter 85 %)
- der Heartbeat an die Überwachung von außen schlägt seit 15 Minuten fehl

**Erledigtes:**

- Backup, Snapshot oder Wiederherstellung fertig
- Server neu gestartet
- Updates eingespielt, automatisch oder von Hand
- eine neue Version von OWPanel ist erschienen, einmal je Version
- jede fehlgeschlagene Aufgabe, etwa ein Zertifikat oder eine Firewall-Änderung

**Sicherheit:**

- ein Administrator meldet sich von einem neuen Gerät an, mit Browser und Adresse. Das Panel erkennt Geräte an einem eigenen Cookie; die erste Anmeldung nach dem Update lernt nur das Gerät, von dem du dich gerade anmeldest. Wer Cookies löscht oder privat surft, gilt beim nächsten Mal als neues Gerät.
- eine Anmeldung wurde nach zu vielen Fehlversuchen gesperrt, höchstens einmal pro Stunde und Konto und nur für Konten, die es gibt
- eine [Sperrliste](docs:servers#sperrlisten) der Firewall ließ sich zweimal hintereinander nicht abrufen; es gilt weiter ihr letzter guter Stand, die Entwarnung kommt mit dem nächsten erfolgreichen Abruf

## E-Mail

Den Versand richtest du unter **System → E-Mail-Versand** ein, mit dem SMTP-Server deines E-Mail-Anbieters. **Testmail an mich senden** zeigt, ob alles passt.

## Push

1. Öffne **Mein Konto → Benachrichtigungen** auf dem Gerät, das Nachrichten bekommen soll.
2. Klicke auf **Dieses Gerät aktivieren** und erlaube Benachrichtigungen im Browser.

::: info
Auf iPhone und iPad fügst du das Panel zuerst über **Teilen → Zum Home-Bildschirm** hinzu und öffnest es von dort (ab iOS 16.4). Erst dann erlaubt Safari Push-Nachrichten.
:::

Am Icon der installierten App zeigt eine Zahl, wie viele Warnungen noch offen sind, etwa ein nicht erreichbarer Server oder ein fehlgeschlagenes Backup. Sie ändert sich mit jeder Push-Nachricht und beim Öffnen des Panels und verschwindet, wenn alles in Ordnung ist. Auf iPhone und iPad schaltest du sie unter **Einstellungen → Mitteilungen → OWPanel → Kennzeichen** ein oder aus.

Unter jedem Gerät zeigt **Letzte Zustellungen** die letzten 20 Push-Nachrichten mit Uhrzeit und der Antwort des Push-Dienstes: **angenommen**, **abgelehnt** mit dem Fehlercode oder **nicht erreicht**. Steht dort „angenommen“, hat das Panel die Nachricht zu dieser Zeit abgegeben; zeigt das Gerät sie später an, liegt es am Gerät oder am Push-Dienst, etwa an einer zeitgesteuerten Zusammenfassung oder am Stromsparmodus. Dieselben Einträge stehen unter **System → Logs** im Log des Abfrage-Workers.

Die Nachrichten laufen Ende zu Ende verschlüsselt über den Push-Dienst des Browsers (Apple, Google, Mozilla oder Microsoft). Er sieht nur, dass eine Nachricht kommt, nicht ihren Inhalt.

## Überwachung von außen

Fällt der Panel-Server aus, kann er das selbst nicht mehr melden. Dafür gibt es unter **System → Externe Dienste** die **Überwachung von außen**: Das Panel ruft alle fünf Minuten eine Adresse deines Überwachungsdienstes auf, etwa von Healthchecks, Uptime Kuma (Monitor-Typ „Push“) oder ipv64. Es ruft nur, solange Zeitplan und beide Hintergrunddienste des Panels laufen. Bleiben die Aufrufe aus, meldet sich der Überwachungsdienst bei dir.

1. Lege bei deinem Überwachungsdienst einen Check mit fünf Minuten Abstand und etwa zehn Minuten Toleranz an.
2. Trage dessen Adresse unter **Heartbeat-Adresse** ein und klicke auf **Prüfen und speichern**. Das Panel ruft sie sofort einmal auf.

Erlaubt sind nur HTTPS-Adressen im öffentlichen Internet.

## Ruhezeiten

In den Ruhezeiten, etwa nachts, warten alle Meldungen. Danach kommt alles Angefallene gesammelt in einer Nachricht je Weg.
