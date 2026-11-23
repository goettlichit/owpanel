---
title: Sicherheit
description: Wie OWPanel Server, Webseiten und Zugänge schützt.
group: Mehr
icon: shield-lock
slug: security
---

Sicherheit hat in OWPanel Vorrang vor Komfort. Diese Regeln gelten von der ersten Installation an.

## Anmeldung

- Administratoren melden sich mit Passwort **und** Zwei-Faktor an. Ohne eingerichteten zweiten Faktor geht es nicht weiter.
- Wahlweise geht es mit einem Passkey über deine eigene [Pocket-ID-Instanz](docs:pocket-id). Das Panel prüft dabei jede Antwort streng (PKCE, Signatur, Aussteller, Einmalwerte) und ordnet Konten nie über die E-Mail-Adresse zu. Passwort und Zwei-Faktor bleiben als Notfallzugang immer erhalten.
- Nach 60 Minuten ohne Aktivität endet die Sitzung.
- Anmeldeversuche und alle Formulare, die etwas ändern, sind in ihrer Zahl pro Minute begrenzt. Sperrt das Panel eine Anmeldung nach zu vielen Fehlversuchen, kommt eine [Benachrichtigung](docs:notifications).
- Meldet sich ein Administrator von einem neuen Gerät an, kommt ebenfalls eine Benachrichtigung mit Browser und Adresse.
- Das Audit-Log hält Anmeldungen und alle wichtigen Aktionen fest. Das Auge am Ende einer Zeile zeigt alle Angaben eines Eintrags, etwa alten und neuen Wert, Browser und das betroffene Objekt.

## Im Browser

- Das Panel ist nur über HTTPS erreichbar und weist den Browser per HSTS an, es ein Jahr lang nur noch verschlüsselt aufzurufen.
- Eine Content Security Policy lässt nur Skripte des Panels selbst zu. Eingeschleuste Skripte führt der Browser nicht aus.
- Das Panel lässt sich nicht in fremde Seiten einbetten.

## Steuerung des Servers

- Das Panel ändert den Server nie direkt. Es verbindet sich per SSH als Benutzer `owpanel-agent` und darf über `sudo` nur fest eingebaute Skripte unter `/opt/owpanel/bin/` ausführen.
- Jedes Skript prüft seine Eingaben selbst und lässt sich gefahrlos wiederholen.
- Den SSH-Host-Schlüssel jedes Servers speichert das Panel und prüft ihn bei jeder Verbindung.
- Releases sind signiert. `get-owpanel` installiert ein Release nur mit gültiger Signatur des Projekts; unter **Server → Zustand** siehst du, ob der hinterlegte Schlüssel zu dem des Panels passt.
- Nur der Hintergrund-Worker `owpanel-worker` kann den SSH-Schlüssel des Panels lesen, nicht die Weboberfläche.
- Erzeugte Konfigurationen werden vor dem Aktivieren geprüft. Bei Fehlern bleibt die vorherige Fassung aktiv.

## Webseiten

- Jede Webseite hat einen eigenen Linux-Benutzer mit zufälliger Nummer und einen eigenen PHP-FPM-Pool.
- Fremde Webseiten-Ordner sind nicht lesbar.
- Datenbankbenutzer haben nur Rechte auf ihre eigene Datenbank und verbinden sich nur vom Server selbst.
- SFTP-Zugänge haben keine Shell und sehen nur den Ordner ihrer Webseite.

## Netzwerk

- Die nftables-Firewall lässt nur TCP 22, 80 und 443 sowie UDP 443 zu, dazu was du selbst freigibst.
- Firewall-Änderungen werden automatisch zurückgenommen, wenn das Panel den Server danach nicht mehr erreicht.
- fail2ban sperrt Adressen nach wiederholten Fehlanmeldungen und Scanner, die versteckte Dateien wie `.env` oder `.git` suchen. Diese Dateien liefert NGINX auf keiner Webseite aus.
- Wahlweise weisen [Sperrlisten](docs:servers#sperrlisten) bekannte Angreifer auf allen Ports ab. Deine Ausnahmen und die Adressen des Panels bleiben immer erreichbar.
- Unter Server → SSH schaltest du die Passwort-Anmeldung per SSH ab, sobald root und jedes Konto mit Anmeldung einen Schlüssel haben ([Server](docs:servers#ssh)).

## Daten

- Passwörter, Tokens und Schlüssel speichert das Panel verschlüsselt.
- Sicherungen sind mit restic verschlüsselt; jeder Server hat sein eigenes Repository und seinen eigenen Subaccount.
- Das Panel lädt keine Schriften, Skripte oder Bilder von fremden Servern und sendet keine Nutzungsdaten.

## Sicherheitslücken melden

Hast du eine Sicherheitslücke gefunden, melde sie bitte nicht öffentlich, sondern vertraulich an goettlichIT. Wie das geht, steht in der Datei `SECURITY.md` im Quellcode.
