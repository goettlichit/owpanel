---
title: Datenbanken
description: MariaDB-Datenbanken anlegen, ansehen, exportieren und importieren.
group: Betrieb
icon: database
slug: databases
---

## Datenbank anlegen

Datenbanken gehören immer zu einer Webseite. Unter **Webseite → Datenbanken → Neue Datenbank** gibst du einen Namen ein; das Panel stellt den Namen der Webseite davor, etwa `ow38217_shop`, und legt einen Benutzer mit demselben Namen und einem zufälligen Passwort an. Der Benutzer hat nur Rechte auf seine eigene Datenbank.

Die Zugangsdaten stehen bei der Datenbank. Verbunden wird nur vom Server selbst:

| Feld | Wert |
|---|---|
| Host | `localhost` oder `127.0.0.1` |
| Port | `3306` |
| Zeichensatz | `utf8mb4` |

Mit **Passwort anzeigen** zeigt das Panel das Passwort im Klartext; jedes Anzeigen steht im Audit-Log. Unter **Neues Passwort** setzt du ein neues.

## Datenbank-Werkzeug

Statt phpMyAdmin hat das Panel ein eigenes, schlankes Werkzeug:

- **Tabellen:** Name, Zeilen, Größe und Engine jeder Tabelle
- **Inhalt:** die Zeilen einer Tabelle, seitenweise, mit ihren Spalten
- **SQL-Befehl:** eigene Abfragen ausführen, auch mehrere nacheinander

## Export und Import

- **Exportieren** erstellt eine mit gzip komprimierte SQL-Datei mit Tabellen, Daten, Ansichten, Triggern und Routinen. Sie läuft als Aufgabe; danach lädst du die Datei herunter.
- **Importieren** spielt eine `.sql`- oder `.sql.gz`-Datei ein, etwa einen Export aus einem anderen Panel oder aus phpMyAdmin. Angaben wie `DEFINER` anderer Benutzer entfernt das Panel. Der Import stoppt beim ersten Fehler.

::: warning
Ein Import kann vorhandene Tabellen ersetzen. Lege vorher eine Sicherung an, etwa mit **Jetzt sichern** unter **Webseite → Backups**.
:::
