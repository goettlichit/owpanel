---
title: Server
description: Zustand, Auslastung, Updates, Neustart, Firewall, SSH, DNS und fail2ban.
group: Betrieb
icon: server-2
slug: servers
---

## Zustand und Auslastung

Das Panel fragt jeden Server einmal pro Minute ab. Unter **Server → Zustand** siehst du Betriebssystem, Kernel, Laufzeit, die Version der Serverskripte, den Fingerabdruck des Release-Schlüssels (mit grünem Haken, wenn er zum Panel passt), Prozessor, Arbeitsspeicher und Festplatte, unter **Auslastung** den Verlauf. **Dienste** zeigt, ob nginx, PHP-FPM, MariaDB, fail2ban und nftables laufen, soweit sie auf dem Server eingeschaltet sind; steht einer, kommt eine [Benachrichtigung](docs:notifications).

Hat der Server mehr als eine Netzwerkkarte, etwa zusätzlich ein privates Netzwerk der Hetzner Cloud, zeigt die Auslastung den Verkehr für jede Karte getrennt: „Netzwerk öffentlich“ ist die Karte mit der Standardroute ins Internet, „Netzwerk privat“ jede weitere. Einstellen musst du dafür nichts.

## Updates

Debian-Updates spielt `unattended-upgrades` jede Nacht von selbst ein: Sicherheitsupdates von Debian sowie alle Updates von nginx.org und packages.sury.org (PHP). Unter **Server → Updates** siehst du:

- ob die automatischen Updates aktiv sind und wann sie zuletzt liefen
- welche Updates noch ausstehen, Sicherheitsupdates hervorgehoben
- welche Pakete zuletzt eingespielt wurden, automatisch oder von Hand

Das Panel prüft das stündlich. **Jetzt prüfen** lädt zuerst die Paketlisten aller Quellen frisch und liest die Liste danach neu ein. Mit **Alle installieren** spielst du genau diese Liste ein (`apt-get dist-upgrade`), auch die normalen Debian-Updates, die nachts nicht automatisch kommen. Geänderte Konfigurationsdateien bleiben dabei, wie sie sind; danach kann ein Neustart nötig sein. Einen Wechsel der Debian-Version spielt das Panel bewusst nicht ein.

Auf dem Server, auf dem das Panel läuft, zeigt die Karte **OWPanel** die installierte und die neueste veröffentlichte Version; das Panel fragt einmal am Tag bei GitHub nach. Ist eine neuere Version erschienen, führt **Änderungen** zu ihren Release-Notizen. Mit dem Haken „Änderungen gelesen“ und **Panel aktualisieren** spielst du sie ein: Der Server lädt das Release, prüft Prüfsumme und Signatur, wartet höchstens zehn Minuten auf laufende Aufgaben und startet dann den Installer. Das Panel ist dabei kurz nicht erreichbar; die Seite lädt sich neu, sobald die neue Version läuft. Daten und Einstellungen bleiben erhalten. Schlägt etwas fehl, läuft die bisherige Version weiter, und unter **Letztes Update** steht der Grund.

Mit **Fehlerbehebungen automatisch einspielen** aktualisiert sich das Panel nachts um 4:47 Uhr selbst, aber nur auf Patch-Versionen (etwa 1.2.3 auf 1.2.4) und frühestens einen Tag nach ihrem Erscheinen. Laufen gerade Aufgaben, versucht es das Panel in der nächsten Nacht wieder. Größere Versionen spielst du immer von Hand ein; eine Testversion wird nie automatisch aktualisiert. Von Hand geht es auch weiterhin auf dem Server mit `get-owpanel`.

## Neustart

Braucht ein Update einen Neustart, zeigt das Panel einen Hinweis auf der Übersicht, in der Serverliste und beim Server. Neu gestartet wird nie von selbst. Unter **Server → Updates → Server neu starten** gibst du zur Bestätigung den Servernamen ein; der Server startet 15 Sekunden später neu.

## Firewall

Die Firewall nutzt nftables. Offen sind TCP 22 (SSH), 80 und 443 sowie UDP 443 für HTTP/3. Unter **Server → Firewall** gibst du weitere Ports frei, wahlweise nur für bestimmte Adressen oder Netze, etwa `203.0.113.0/24`.

::: tip
Jede Änderung an der Firewall wird automatisch zurückgenommen, wenn das Panel den Server danach nicht mehr per SSH erreicht. So sperrst du dich nicht aus.
:::

### Sperrlisten

Unter **Server → Firewall → Sperrlisten** trägst du Adressen öffentlicher Sperrlisten ein, etwa von ipv64.net, blocklist.de, FireHOL oder Spamhaus DROP. Adressen aus diesen Listen erreichen den Server dann auf keinem Port mehr.

- Das Panel lädt die Liste sofort und prüft das Format: eine Adresse oder ein Netz am Zeilenanfang, Kommentare mit `#`, `;` oder `//`, oder JSON-Zeilen mit `cidr`. Ist es keine Sperrliste, sagt das Panel, was nicht stimmt.
- Jeder Liste kannst du einen eigenen Namen geben, etwa „Spamhaus IPv6“; ohne Namen zeigt das Panel die Adresse. Name und Aktualisierung lassen sich später in der Tabelle ändern.
- Wie oft das Panel die Liste neu holt, wählst du je Liste: jede Stunde, alle 6 Stunden oder einmal am Tag. Mit **Jetzt aktualisieren** holst du sie sofort. Ist eine Liste einmal nicht erreichbar, gilt weiter der letzte gute Stand.
- Private und reservierte Netze und sehr große Netze übernimmt das Panel nicht. Deine Adressen unter **Nie sperren** (fail2ban) und die Adressen des Panels bleiben immer erreichbar.
- Steht deine eigene Adresse in einer Liste, nimmt das Panel sie nicht an, bis du die Adresse unter **Nie sperren** einträgst.
- Höchstens 10 Listen und zusammen 250.000 Einträge je Server.
- Unter der Tabelle siehst du, welche Adressen am häufigsten abgewiesen wurden, wann zuletzt und aus welcher deiner Listen. In der Tabelle steht je Liste, wie viele Treffer sie gebracht hat. So erkennst du, welche Liste sich lohnt. Gezählt wird ab der Einrichtung; eine Adresse ohne Treffer in 7 Tagen fällt heraus, und nach einem Neustart des Servers beginnt die Zählung neu.
- Steht eine deiner eigenen Adressen auf einer Liste, warnt die Karte: die öffentlichen Adressen deiner Server, deine aktuelle Adresse und die Adressen unter **Nie sperren**. Dabei steht, ob die Adresse als Ausnahme geschützt ist. Steht eine Server-Adresse auf einer öffentlichen Liste, sperren dich vor allem andere aus.
- Unter **Adresse prüfen** gibst du eine beliebige IPv4- oder IPv6-Adresse ein und siehst, auf welchen deiner Listen sie steht, etwa wenn sich ein Kunde meldet, weil er nicht durchkommt. Die Adresse wird nicht gespeichert.

## SSH

Unter Server → **SSH** siehst du, ob sich Administratoren per SSH mit Passwort anmelden können, und für root und jedes andere Konto mit Anmeldung, ob ein SSH-Schlüssel hinterlegt ist. **Passwort-Anmeldung abschalten** lässt danach nur noch Schlüssel zu; SFTP-Kunden behalten ihr Passwort. Das Panel schaltet nur ab, wenn jedes dieser Konten einen Schlüssel hat, damit du dich nicht aussperrst. **Wieder erlauben** nimmt die Einstellung zurück.

Nutzt sshd `AllowUsers` oder `AllowGroups`, ohne die Gruppe `owpanel-sftp` zu erlauben, kommen SFTP-Kunden nicht herein. Das zeigt die Seite ebenso wie die SFTP-Seite einer Webseite.

## DNS

Unter Server → **DNS** siehst du, ob der Hostname des Servers (etwa `srv03.example.de`) per A und AAAA auf seine Adressen zeigt, wie bei der DNS-Seite einer Webseite. Liegt die Zone bei Hetzner, setzt **Einträge setzen** fehlende oder falsche Einträge.

Darunter steht der **Reverse DNS (PTR)** jeder Adresse: welcher Name zur IP gehört und ob dieser Name zurück auf die IP zeigt. Bei Hetzner-Servern trägst du den Namen direkt ein, vorbelegt mit dem Hostnamen; ein leeres Feld setzt den Eintrag auf den Standard von Hetzner zurück. Bei anderen Anbietern zeigt das Panel den Eintrag nur an. Ein passender Reverse DNS ist vor allem für den Mailversand wichtig.

## fail2ban

fail2ban sperrt Adressen, die sich wiederholt falsch per SSH anmelden: nach 5 Versuchen in 10 Minuten für eine Stunde. Außerdem sperrt es Scanner, die auf deinen Webseiten nach versteckten Dateien wie `.env` oder `.git` suchen: nach 5 solchen Abrufen in 10 Minuten für 24 Stunden, und zwar nur für die Webseiten, SSH bleibt erreichbar. Solche Dateien liefert NGINX ohnehin nie aus; die Sperre hält den Scanner nur von weiteren Versuchen ab. Unter **Server → Firewall** siehst du die gesperrten Adressen, kannst sie entsperren und eigene Adressen unter **Nie sperren** eintragen.

## Zeitzone

Die Zeitzone des Servers legt der Installer fest. Das Panel zeigt alle Zeiten in dieser Zeitzone an und gleicht die Uhrzeit per NTP ab.

## Weitere Server (geplant)

Heute verwaltet das Panel den Server, auf dem es läuft. Nach dem ersten Release kommen weitere Server dazu, ohne Neuinstallation und ohne Migration:

- Unter **Server → Server hinzufügen** zeigt das Panel einen Befehl mit einem Einmal-Code. Du führst ihn auf einem frischen Debian-13-Server aus, der im selben privaten Netzwerk der Hetzner Cloud hängt.
- Das Panel bleibt das Eingangstor: Alle Domains zeigen auf das Panel, es kümmert sich um Zertifikate und HTTP/3 und reicht jede Anfrage an den Server der Webseite weiter. Die weiteren Server sind von außen nur für SSH und SFTP erreichbar.
- Beim Anlegen einer Webseite schlägt das Panel den Server mit der meisten Reserve vor. Umziehen kannst du eine Webseite später von Hand, ihre Sicherungen ziehen mit.
- Für eigene Dienste wie Pocket ID kommen danach Docker-Apps dazu, nur für Administratoren.

::: tip
Schon jetzt sinnvoll: Lege alle Server in derselben Netzwerkzone an (bei Hetzner etwa Nürnberg, Falkenstein und Helsinki) und nimm sie in eine gemeinsame **Platzierungsgruppe** vom Typ „Spread“. Dann laufen sie auf verschiedenen physischen Rechnern, und der Ausfall eines Rechners trifft nur einen Server. Die Gruppe kostet nichts; bestehende Server lassen sich nur ausgeschaltet aufnehmen. Hilfreich sind auch Namen nach der Aufgabe, etwa `gw01` für den Panel-Server und `web01`, `web02` für weitere Server.
:::
