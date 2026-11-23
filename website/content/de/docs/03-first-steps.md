---
title: Erste Schritte
description: Anmelden, Zwei-Faktor einrichten und das Panel kennenlernen.
group: Einstieg
icon: rocket
slug: first-steps
---

## Anmelden und Zwei-Faktor einrichten

Beim ersten Anmelden verlangt das Panel die Zwei-Faktor-Authentifizierung. Du brauchst dafür eine App wie Aegis, 2FAS, Google Authenticator oder einen Passwort-Manager mit TOTP.

1. Scanne den QR-Code mit der App oder gib den Schlüssel von Hand ein.
2. Bestätige mit dem sechsstelligen Code aus der App.
3. Speichere die **Wiederherstellungscodes** an einem sicheren Ort. Mit ihnen kommst du ins Panel, falls das Handy verloren geht.

Unter **Mein Konto** änderst du später im **Profil** dein Passwort, unter **Anmeldung und Sicherheit** Zwei-Faktor und die [Anmeldung mit Pocket ID](docs:pocket-id), unter **Benachrichtigungen** deine Meldungen.

## Der Aufbau der Oberfläche

- **Oben** stehen die Bereiche: Übersicht, Webseiten, Kunden, Server und System. Rechts daneben wechselst du Sprache und Darstellung (System, Hell, Dunkel).
- **Links** steht das Menü des geöffneten Objekts, etwa bei einem Server Zustand, Auslastung, Updates, Firewall, SSH, DNS, Snapshots, Backups, Aufgaben und Logs.
- **Unten** stehen Version, Lizenz und der Link zum Quellcode.

Karten zeigen ihren Zustand mit einer farbigen Linie oben: **Blau** heißt nicht eingerichtet oder aus, **Grün** in Ordnung, **Orange** in Arbeit oder Achtung, **Rot** fehlgeschlagen.

## Aufgaben

Alles, was den Server verändert, läuft als **Aufgabe** im Hintergrund: Webseite anlegen, Zertifikat anfordern, Firewall ändern, Sicherung starten. Du bleibst dabei auf der Seite: Der Knopf zeigt einen Spinner mit dem, was gerade passiert, und wenn die Aufgabe fertig ist, lädt die Seite neu und meldet kurz „erledigt“ oder „fehlgeschlagen“. Jede Aufgabe hat einen Status (Wartend, Läuft, Erfolgreich, Fehlgeschlagen) und ein Protokoll. Du findest sie unter „Aufgaben“ beim Server oder bei der Webseite, zu der sie gehören; bei einem Fehler führt der Link „Details“ direkt dorthin.

## Abmeldung

Das Panel fragt nach der Anmeldung nicht noch einmal nach deinem Passwort. Nach 60 Minuten ohne Aktivität meldet es dich ab; melde dich ab, wenn du den Rechner verlässt.

## Empfohlene Reihenfolge

1. Unter **System → E-Mail-Versand** den Versand einrichten, damit Benachrichtigungen ankommen.
2. Unter **Mein Konto → Benachrichtigungen** festlegen, welche Meldungen du per E-Mail und Push bekommst.
3. Unter **System → Externe Dienste** das API-Token der Hetzner Cloud eintragen und die [Snapshots](docs:snapshots) sowie die [Backups](docs:backups) einrichten.
4. Optional unter **System → Externe Dienste** die [Überwachung von außen](docs:notifications#ueberwachung-von-aussen) eintragen, damit du erfährst, wenn das Panel selbst ausfällt.
5. Die erste [Webseite](docs:websites) anlegen.
