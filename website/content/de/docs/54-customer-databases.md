---
title: Datenbanken
description: MariaDB-Datenbanken anlegen, ansehen, exportieren und importieren.
audience: customer
icon: database
slug: customer-databases
---

## Datenbank anlegen

Unter **Datenbanken → Neue Datenbank** geben Sie einen kurzen Namen ein, etwa `shop`. Das Panel stellt eine Kennung davor, zum Beispiel `ow38217_shop`, und legt einen Benutzer mit demselben Namen und einem zufälligen Passwort an. Wie viele Datenbanken Sie haben können, legt Ihr Paket fest.

Die Zugangsdaten für Ihre Anwendung stehen bei der Datenbank:

| Feld | Wert |
|---|---|
| Host | `localhost` oder `127.0.0.1` |
| Port | `3306` |
| Datenbank und Benutzer | der volle Name, etwa `ow38217_shop` |
| Zeichensatz | `utf8mb4` |

Mit **Anzeigen** sehen Sie das Passwort, mit **Neues Passwort** setzen Sie ein neues. Tragen Sie es danach auch in Ihrer Anwendung ein. Verbindungen sind nur von Ihrer Webseite aus möglich, nicht von Ihrem Computer.

## Inhalte ansehen

Das Datenbank-Werkzeug zeigt die **Tabellen** mit Zeilen und Größe, den **Inhalt** einer Tabelle seitenweise und führt unter **SQL-Befehl** eigene Abfragen aus.

## Export und Import

- **Exportieren** erstellt eine komprimierte SQL-Datei mit allen Tabellen und Daten. Nach kurzer Zeit laden Sie sie herunter.
- **Importieren** spielt eine `.sql`- oder `.sql.gz`-Datei ein, etwa einen Export von Ihrem bisherigen Anbieter oder aus phpMyAdmin, bis 256 MB. Ist Ihre Datei größer, komprimieren Sie sie mit gzip oder fragen Sie Ihren Anbieter. Der Import stoppt beim ersten Fehler.

::: warning
Ein Import kann vorhandene Tabellen überschreiben. Sichern Sie vorher unter **Backups** mit **Jetzt sichern**.
:::

## Datenbank löschen

**Löschen** entfernt die Datenbank und ihren Benutzer endgültig. Exportieren Sie sie vorher, wenn Sie die Daten noch brauchen.
