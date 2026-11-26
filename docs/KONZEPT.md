# Konzept

## Problem

Viele Control-Panels sind für den gewünschten Einsatz zu mächtig oder nur für einen einzelnen Server ausgelegt. Docker-basierte Plattformen passen außerdem nicht zu jedem klassischen PHP-Hosting. Eine einfache, native und zweisprachige Lösung mit Multi-Server-Betrieb soll diese Lücke schließen.

## Vision

Das Panel auf einem frischen Debian-Server installieren und wenige Minuten später die erste Website mit SSL, Datenbank und Backup betreiben, ohne dafür Konfigurationsdateien bearbeiten zu müssen. Wächst der Bedarf, kommen weitere Server hinzu, ohne dass neu installiert oder migriert werden muss.

## Zielgruppe

Vorläufige Annahme:

- Entwickler und technisch versierte Selbstständige
- Kleine Agenturen, die Kundenwebsites betreuen
- Admins mit wenigen bis etwa 20 Servern
- Vor allem Nutzer im deutschsprachigen Raum
- Selbst-Hoster aus der Open-Source-Community, die das Projekt über GitHub finden
- Kleine Hoster, die Webspace an eigene Kunden vermieten und die Abrechnung in einem eigenen System führen

Reseller und große Hosting-Anbieter gehören zunächst nicht zur Zielgruppe. Kunden-Logins sind keine Reseller-Funktion: Es gibt nur zwei Ebenen, den Betreiber des Panels und seine Kunden.

## Infrastruktur: Hetzner Cloud zuerst

OWPanel wird zunächst für Server in der Hetzner Cloud entwickelt und dort getestet (E-105). Zwei Funktionen hängen direkt an Hetzner:

- **Snapshots** ganzer Server über die Hetzner Cloud API
- **Backups** mit restic auf eine Hetzner Storage Box, mit einem Subaccount je Server, den das Panel über die Hetzner-API anlegt

Alles andere ist anbieterunabhängig: NGINX, PHP-FPM, MariaDB, Zertifikate, Firewall, Updates und Benachrichtigungen brauchen nur Debian 13. Für den späteren Multi-Server-Betrieb müssen weitere Server zusätzlich im selben privaten Hetzner-Netz wie der Panel-Server hängen (E-166); andere Anbieter kommen dafür erst mit WireGuard in Frage. Auf anderen Anbietern sollte das Panel deshalb ohne Snapshots und Backups laufen; geprüft ist das noch nicht. Weitere Backup-Ziele wie S3 oder die Snapshot-Schnittstellen anderer Anbieter können folgen, wenn Bedarf besteht, und brauchen jeweils eine eigene Entscheidung.

## Produktprinzipien

1. **Einfachheit vor Vollständigkeit:** Jede Funktion muss ihren Platz im Panel rechtfertigen.
2. **Gute Standardwerte:** Eine Website soll mit möglichst wenigen Eingaben funktionieren.
3. **Keine Überraschungen:** Das Panel zeigt, was es tut und welche Fehler aufgetreten sind.
4. **Gleichwertige Sprachen:** Keine englischen Reste in der deutschen Oberfläche und umgekehrt.
5. **Standardwerkzeuge:** NGINX, PHP-FPM und MariaDB bleiben normal per SSH administrierbar.

## Umfang der ersten Version

### Enthalten

- Ersteinrichtung per Installationsbefehl, der Domain, Zeitzone und ersten Administrator abfragt (E-069)
- Benutzeranmeldung mit Passwort aus der Panel-Datenbank und Zwei-Faktor-Authentifizierung
- Alternativ Anmeldung über Pocket ID (OIDC)
- Audit-Log
- Serverstatus und Einrichtungslogs
- Websites: PHP, Node.js, statisch und Reverse Proxy
- PHP 8.5 und 8.6 von packages.sury.org, je Website wählbar, und NGINX von nginx.org (E-042, E-172)
- Eigener Linux-Benutzer pro Website
- Let's-Encrypt-Zertifikate
- HTTP/3 über QUIC für alle Websites mit Zertifikat, mit HTTP/2 und HTTP/1.1 als Rückfall
- MariaDB-Datenbanken und Datenbankbenutzer
- Datenbank-Werkzeug im Panel: Tabellen ansehen, Inhalte durchblättern, SQL ausführen (E-079)
- SFTP-Zugänge
- Cronjobs
- Logs
- Backups mit restic auf eine Hetzner Storage Box (täglich oder bis stündlich), Hetzner-Snapshots, Wiederherstellung einzelner Websites und des ganzen Panels
- Benachrichtigungen per E-Mail und Web-Push für Administratoren
- Firewall-Verwaltung mit nftables, einschließlich UDP für QUIC
- Besucherstatistik je Website aus den Access-Logs, nur Zahlen je Tag, ohne Skript auf der Website und ohne gespeicherte Adressen (E-148)
- DNS-Einträge (A und AAAA) der Websites prüfen und über die Hetzner-API anlegen, korrigieren und entfernen (E-116); einen eigenen DNS-Server betreibt das Panel nicht
- Oberfläche auf Basis von Tabler, auf Deutsch und Englisch
- Kundenbereich: Kunden melden sich an und verwalten nur ihre eigenen Websites
- Pakete mit Grenzen je Kunde, etwa Websites, Datenbanken, Cronjobs und Speicherplatz mit Quota (E-125, E-126); Preise und Verträge bleiben im externen System
- Websites sperren und entsperren

### Nach der ersten Version

Geklärt am 2026-11-20 (E-161 bis E-169); die erste Version erscheint als Standalone und lässt sich ohne Migration erweitern.

- Weitere Server nur beobachten, etwa eine Mailcow-VM: Zustand, Debian-Updates, Neustart und Benachrichtigungen im Panel, ohne dass das Panel dort etwas einrichtet (E-189)
- Weitere Server hinter dem Panel-Server als Eingangstor (Gateway): Alle Domains zeigen auf das Panel, es reicht Anfragen über das private Netz an den Server der Website weiter; Websites werden beim Anlegen auf den Server mit der meisten Reserve vorgeschlagen (E-161)
- Eine eigene IPv6-Adresse je Website (E-162)
- Websites zwischen Servern umziehen, mit ihren Sicherungen (E-168)
- Docker-Apps für Administratoren: einzelne Compose-Projekte auf einem gewählten Server, erreichbar über das Gateway, als Ersatz für einen eigenen Reverse-Proxy-Manager (E-163)
- API für die Anbindung der eigenen Homepage: Kunden, Pakete, Status und Verbrauch lesen, Zahlungshinweis setzen (E-165)

### Bewusst nicht enthalten

- E-Mail-Server, auch keine Weiterleitung von Mails der Websites; Websites senden selbst per SMTP (E-177)
- DNS-Server
- Reseller-Funktionen, also Kunden, die selbst Unterkunden verwalten
- Abrechnung, Rechnungen und Kundenstammdaten wie Adressen oder Steuernummern; diese bleiben im externen System
- App-Store mit vielen Ein-Klick-Anwendungen
- Docker-Orchestrierung über mehrere Server (Swarm, Kubernetes) und Docker-Apps für Kunden; einzelne Compose-Projekte für Administratoren kommen mit E-163
- Ausfallsicherheit einzelner Websites über mehrere Server (E-161)
- Andere Betriebssysteme als Debian

Diese Liste kann sich ändern, sollte aber nur durch eine dokumentierte Entscheidung wachsen.

## Marktüberblick

Stand: November 2026. Die Angaben stammen aus einer ersten Recherche und sind noch nicht durch eigene Tests bestätigt.

| Lösung | Einordnung | Offener Punkt |
|---|---|---|
| CloudPanel | Schlanker Stack, Vorbild für die Einfachheit | Multi-Server-Fähigkeit und deutsche Oberfläche testen |
| HestiaCP | Schlank, gut für einzelne Server | Multi-Server-Grenzen testen |
| ISPConfig | Stark bei Multi-Server und Resellern | Einrichtungsaufwand und Bedienung testen |
| Coolify | Funktionsreiche Docker-Plattform | Passung für klassisches PHP-Hosting testen |
| Dokploy | Docker-Swarm-Plattform | Passung für klassisches PHP-Hosting testen |
| Openship | Multi-Server-Plattform mit deutscher Projektbeschreibung | Funktionen und Reifegrad prüfen |

Das geplante Alleinstellungsmerkmal ist die Kombination aus einfacher Bedienung, nativem Hosting, Multi-Server und zwei gleichwertigen Sprachen.

## Erfolgskriterien für die erste Version

- Die Ersteinrichtung ist in unter 15 Minuten abgeschlossen.
- Eine fremde Person kann das Projekt allein anhand der GitHub-Anleitung installieren.
- Eine PHP-Website mit SSL entsteht in unter 2 Minuten.
- Ein Backup lässt sich vollständig wiederherstellen.
- Ein Kunde sieht in keinem Fall Daten eines anderen Kunden.
- Alle sichtbaren Texte liegen auf Deutsch und Englisch vor.
- Ein externer Testnutzer schafft die Einrichtung ohne Hilfe.

Für die Zeit danach (E-169):

- Eine Standalone-Installation bekommt ohne Migration einen zweiten Server.
- Die eigene Homepage liest über die API Kunden, Pakete und den Status der Websites und setzt einen Zahlungshinweis.
