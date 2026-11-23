---
title: Backups
description: Webseiten und Panel mit restic auf eine Hetzner Storage Box sichern.
group: Sicherung
icon: database-export
slug: backups
---

OWPanel sichert Dateien und Datenbanken jeder Webseite mit [restic](https://restic.net) auf eine Hetzner Storage Box. Die Sicherungen sind verschlüsselt, werden dedupliziert und lassen sich einzeln durchsuchen und zurückspielen.

## So ist es aufgebaut

- Jeder Server bekommt einen **eigenen Subaccount** auf der Storage Box. Das Panel legt ihn über die Hetzner-API selbst an.
- Jeder Server sichert in ein **eigenes, verschlüsseltes Repository**. Ein Server kommt nicht an die Sicherungen eines anderen.
- Je Webseite gibt es eigene Sicherungen mit ihren Dateien unter `htdocs` und allen ihren Datenbanken.
- Das **Panel selbst** wird mitgesichert: seine Datenbank, die `.env`, die Einstellungen des Installers und sein SSH-Schlüssel.

## Einrichten

1. Lege in der Hetzner Console eine **Storage Box** an, im selben Konto wie das API-Token unter **System → Externe Dienste**.
2. Öffne **Server → Backups** und wähle die Storage Box als **Backup-Ziel**.
3. Lege Uhrzeit, Intervall und Aufbewahrung fest und klicke auf **Speichern und verbinden**.

Das Panel legt den Subaccount an, hinterlegt einen eigenen Schlüssel für den Server und richtet das Repository ein. Die Linie über der Karte wird grün, sobald alles verbunden ist.

Wie voll die Storage Box ist, liest das Panel stündlich bei Hetzner aus und zeigt es unter **System → Backup**. Ab 90 % kommt eine [Benachrichtigung](docs:notifications), damit die Backups nicht erst scheitern, wenn kein Platz mehr da ist.

::: tip
Den Schalter **Auch von außerhalb des Hetzner-Netzes erreichbar** brauchst du nur, wenn der Server nicht bei Hetzner steht.
:::

## Zeitplan und Aufbewahrung

| Einstellung | Bedeutung |
|---|---|
| Uhrzeit | Ab wann gesichert wird, etwa 02:30 |
| Intervall | Täglich oder alle 12, 6, 3 oder 1 Stunden |
| Stündlich | Wie viele Stunden je eine Sicherung bleibt (nur bei kürzeren Intervallen) |
| Täglich, wöchentlich, monatlich | Wie viele Tage, Wochen und Monate je die neueste Sicherung bleibt |

Bei jeder Webseite schaltest du die **automatische Sicherung** unter **Webseite → Backups** ein oder aus.

## Von Hand sichern

- **Jetzt sichern** oben unter **Server → Backups** sichert sofort alle Webseiten des Servers und auf dem Server des Panels auch das Panel.
- **Jetzt sichern** unter **Webseite → Backups** sichert nur diese Webseite.

Sicherungen von Hand bleiben, bis du sie löschst. In den Listen erkennst du die Art an der Farbe: **automatisch** blau, **von Hand** grün, **vor Wiederherstellung** orange.

## Notfalldaten

Unter **System → Backup → Notfalldaten** lädst du eine Textdatei mit allem herunter, was du nach einem Totalausfall brauchst: Storage Box, Subaccounts, Repository-Passwörter. Bewahre sie außerhalb des Servers auf, etwa ausgedruckt oder im Passwort-Manager. Ohne diese Datei lässt sich das Panel nicht aus seiner Sicherung zurückholen.

::: danger
Wer die Notfalldaten hat, kann alle Sicherungen lesen. Behandle die Datei wie ein Passwort.
:::
