---
title: Backups
description: Webseite sichern und ganz oder teilweise wiederherstellen.
audience: customer
icon: database-export
slug: customer-backups
---

Ihr Anbieter sichert Ihre Webseiten regelmäßig, mit allen Dateien in `htdocs` und allen Datenbanken. Die Sicherungen liegen verschlüsselt auf einem anderen System.

## Sicherungen ansehen

Unter **Backups** sehen Sie alle Sicherungen Ihrer Webseite. Die Farbe zeigt die Art: **automatisch** blau, **von Hand** grün, **vor Wiederherstellung** orange.

## Jetzt sichern

**Jetzt sichern** legt sofort eine Sicherung an, etwa vor einem Update Ihrer Anwendung. Sicherungen von Hand bleiben erhalten, bis Ihr Anbieter sie löscht.

## Wiederherstellen

Öffnen Sie eine Sicherung, um ihren Inhalt Ordner für Ordner anzusehen. Dann wählen Sie, was zurückkommt:

| Auswahl | Was passiert |
|---|---|
| Alles | Dateien und alle Datenbanken kommen auf den Stand der Sicherung |
| Nur Dateien | `htdocs` kommt auf den Stand der Sicherung; neuere Dateien verschwinden |
| Ordner oder Datei | nur das Gewählte kommt zurück, sonst ändert sich nichts |
| Nur Datenbanken | alle Datenbanken kommen zurück, die Dateien bleiben |
| Eine Datenbank | nur die gewählte Datenbank kommt zurück |

Vor jeder Wiederherstellung legt das Panel eine Sicherung **vor Wiederherstellung** an. Haben Sie das Falsche zurückgeholt, spielen Sie einfach diese zurück.

## Grenze je Tag

Ihr Paket legt fest, wie oft Sie am Tag selbst sichern und wiederherstellen können; beides zählt zusammen. Die Seite zeigt, wie viele heute noch übrig sind. Die automatischen Sicherungen zählen nicht mit.
