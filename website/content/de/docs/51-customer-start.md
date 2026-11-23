---
title: Erste Schritte
description: Zugang einrichten, anmelden und das eigene Konto absichern.
audience: customer
icon: door-enter
slug: customer-start
---

Ihr Anbieter betreibt Ihre Webseiten mit OWPanel. Im **Kundenbereich** sehen Sie Ihre Webseiten und verwalten Dateizugang, PHP, Datenbanken, Cronjobs und Backups selbst. Webseiten anlegen, löschen oder auf eine andere Domain umziehen erledigt Ihr Anbieter.

## Zugang einrichten

Ihren Zugang bekommen Sie auf einem von zwei Wegen:

- **Einladungslink per E-Mail:** Öffnen Sie den Link und legen Sie Ihr Passwort fest. Der Link gilt 72 Stunden und nur einmal. Ist er abgelaufen, bitten Sie Ihren Anbieter um einen neuen.
- **Start-Passwort:** Ihr Anbieter gibt es Ihnen weiter. Nach der ersten Anmeldung ändern Sie es; vorher geht es nicht weiter.

Ihr Benutzername ist Ihre E-Mail-Adresse.

## Mein Konto

Oben rechts unter Ihrem Namen finden Sie **Mein Konto**:

- **Profil:** Ihr Name. Die E-Mail-Adresse ändert Ihr Anbieter.
- **Anmeldung und Sicherheit:** Passwort ändern und die **Zwei-Faktor-Authentifizierung** einrichten. Dafür brauchen Sie eine App wie Aegis, 2FAS, Google Authenticator oder Ihren Passwort-Manager. Wir empfehlen sie dringend: Ohne den zweiten Faktor genügt ein erratenes oder gestohlenes Passwort, um an Ihre Webseiten zu kommen. Bewahren Sie die Wiederherstellungscodes sicher auf.
- **Passkey:** Hat Ihr Anbieter die Anmeldung mit Pocket ID für Sie freigeschaltet, verknüpfen Sie Ihr Konto dort und melden sich danach auch mit einem Passkey an. Passwort und Zwei-Faktor bleiben als zweiter Weg erhalten.

Nach 60 Minuten ohne Aktivität meldet das Panel Sie ab. Sprache und Darstellung (hell, dunkel oder wie das System) wechseln Sie oben in der Leiste.

## Meine Webseiten

Nach der Anmeldung sehen Sie **Meine Webseiten** mit allen Webseiten, die Ihr Anbieter Ihnen zugeordnet hat. Ein Klick auf eine Webseite öffnet ihr eigenes Menü:

| Menüpunkt | Was Sie dort tun |
|---|---|
| Übersicht | Zertifikat anfordern, www-Adresse ein- und ausschalten |
| DNS | prüfen, ob die Domain auf den Server zeigt |
| PHP | PHP-Version und PHP-Einstellungen |
| SFTP | Dateizugang ein- und ausschalten, Passwort und Schlüssel |
| Datenbanken | anlegen, ansehen, exportieren und importieren |
| Cronjobs | regelmäßige Aufgaben |
| Backups | sichern und wiederherstellen |
| Logs | Zugriffe, Fehler, PHP-Meldungen und Ausgaben der Cronjobs |

## Paket und Speicherplatz

Ihr Paket legt fest, wie viel Speicherplatz, wie viele Datenbanken und Cronjobs Sie haben, wie oft Cronjobs höchstens laufen, wie oft Sie am Tag selbst sichern oder wiederherstellen können und welche PHP-Einstellungen erlaubt sind. Ist eine Grenze erreicht, sind die passenden Knöpfe ausgegraut, mit einem Hinweis daneben.

Der Balken **Speicherplatz** über der Liste zeigt, was Dateien und Datenbanken aller Ihrer Webseiten zusammen belegen. Gemessen wird alle 15 Minuten. Ab 90 % wird er orange, bei 100 % rot. Ist er voll, können Ihre Webseiten keine Dateien mehr speichern, etwa Uploads oder Caches. Geben Sie dann Platz frei, zum Beispiel durch alte Sicherungskopien in `htdocs` oder nicht mehr gebrauchte Datenbanken, oder fragen Sie Ihren Anbieter nach mehr Platz.

## Gesperrte Webseite

Steht bei einer Webseite **Gesperrt**, hat Ihr Anbieter sie vorübergehend abgeschaltet. Besucher sehen dann eine neutrale Seite „vorübergehend nicht erreichbar“. Sie können sich alles ansehen, aber nichts ändern. Ihre Dateien, Datenbanken und Backups bleiben erhalten. Wenden Sie sich in diesem Fall an Ihren Anbieter.
