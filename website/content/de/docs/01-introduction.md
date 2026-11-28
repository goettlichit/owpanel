---
title: Einführung
description: Was OWPanel ist, für wen es gedacht ist und was du dafür brauchst.
group: Einstieg
icon: info-circle
slug: introduction
---

OWPanel (Open Web Panel) ist ein Control-Panel für Debian-Server. Es richtet Webseiten, Datenbanken, Zertifikate, Backups und Benachrichtigungen auf deinem eigenen Server ein, ohne dass du Konfigurationsdateien bearbeiten musst. Die Oberfläche gibt es auf Deutsch und Englisch.

::: warning
OWPanel ist in Version 0.5 und auf Servern in der Hetzner Cloud erprobt. Bis Version 1.0 kann sich noch einiges ändern; lies vor einem Update die Hinweise im [CHANGELOG](https://github.com/goettlichit/owpanel/blob/main/CHANGELOG.md) und halte Backups aktuell.
:::

## Für wen OWPanel gedacht ist

- Für Agenturen und Freiberufler, die Webseiten ihrer Kunden auf eigenen Servern betreiben.
- Für alle, die einen oder mehrere Debian-Server mit PHP-Webseiten einfach verwalten wollen.
- Für alle, die Webseiten nativ betreiben wollen: Webseiten laufen direkt auf dem Server, jede mit eigenem Linux-Benutzer.

## Grundsätze

- **Einfach:** OWPanel kann nur, was für den Betrieb von Webseiten gebraucht wird. Mail-Server, DNS-Server oder ein Shop für Hosting-Pakete gehören nicht dazu.
- **Standalone zuerst:** Die Installation ergibt ein vollständiges Panel auf einem Server. Weitere Server kommen später ohne Migration dazu.
- **Sicher voreingestellt:** Firewall, fail2ban, getrennte Benutzer je Webseite und Zwei-Faktor-Anmeldung sind von Anfang an aktiv.
- **Alles lokal:** Das Panel lädt keine Schriften, Skripte oder Bilder von fremden Servern und sendet keine Nutzungsdaten.
- **Nachvollziehbar:** Jede Aktion auf dem Server läuft als Aufgabe mit Protokoll, und das Audit-Log hält fest, wer was getan hat.

## Was du brauchst

| Was | Wofür |
|---|---|
| Einen frischen Server mit Debian 13 (x86) | Darauf laufen Panel und Webseiten. Empfohlen und getestet: ein Server in der Hetzner Cloud. Mindestens 2 vCPU und 4 GB Arbeitsspeicher; für das Panel mit eigenen Webseiten und späteren weiteren Servern eher 4 vCPU und 8 GB. |
| Zugang als root | Für die Installation, danach nicht mehr nötig. |
| Eine Domain für das Panel | Etwa `panel.example.de`; ihr DNS-Eintrag muss schon auf den Server zeigen. |
| Offene Ports 80 und 443 (TCP) und 443 (UDP) | Für Zertifikate, HTTPS und HTTP/3. Bei einer Hetzner Cloud Firewall dort freigeben. |
| Optional: ein API-Token der Hetzner Cloud | Für Snapshots des ganzen Servers und den Reverse DNS. |
| Optional: eine DNS-Zone bei Hetzner | Damit das Panel DNS-Einträge für Webseiten und Server selbst setzt. |
| Optional: eine Hetzner Storage Box | Für Backups der Webseiten und des Panels. |

Auf anderen Anbietern sollte OWPanel mit Debian 13 laufen, nur ohne Snapshots und Backups. Das ist noch nicht getestet.

## Wie OWPanel arbeitet

Das Panel ist eine Laravel-Anwendung, die auf dem Server selbst läuft. Es ändert den Server nie direkt, sondern startet über SSH nur fest eingebaute, geprüfte Skripte unter `/opt/owpanel/`. Lange Aktionen wie das Anlegen einer Webseite laufen im Hintergrund; du siehst ihren Status und ihr Protokoll live.

Mehr dazu steht unter [Sicherheit](docs:security).

## Lizenz

OWPanel ist freie Software unter der GNU Affero General Public License, Version 3 oder neuer (AGPL-3.0-or-later). Jeder darf es kostenlos und ohne Einschränkung testen, nutzen und verändern. Wer eine veränderte Fassung anderen über das Netz anbietet, muss ihnen den Quellcode dieser Fassung zugänglich machen.

Fehler und Wünsche trägst du als Issue im Repository auf GitHub ein. Sicherheitslücken meldest du bitte vertraulich, siehe [Sicherheit](docs:security#sicherheitsluecken-melden).
