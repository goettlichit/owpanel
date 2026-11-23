# Roadmap

Die Reihenfolge ist vorläufig. Zeitangaben fehlen bewusst, bis erste Erfahrungswerte vorliegen.

## Meilenstein 0: Grundlagen

- [x] Projektbeschreibung und Dokumentation anlegen
- [x] Lizenz wählen und `LICENSE` ergänzen
- [ ] Offene Fragen mit Priorität „vor der GitHub-Veröffentlichung“ und „vor Meilenstein 1“ klären
- [x] Git-Repository anlegen und auf GitHub hochladen (zunächst privat, siehe F-013)
- [ ] Private Sicherheitsmeldungen auf GitHub aktivieren
- [x] Hetzner-Cloud-Server mit Debian 13 als Wegwerf-VM und Snapshot bereitstellen
- [ ] In einer Hetzner Cloud Firewall, falls genutzt, TCP 22, 80, 443 und UDP 443 freigeben
- [x] Test-Subdomain auf den Server zeigen lassen
- [ ] CloudPanel, ISPConfig und Coolify kurz selbst testen

**Fertig, wenn:** Das Repository steht mit Lizenz bereit, und eine frische Test-VM lässt sich in wenigen Minuten wiederherstellen.

## Meilenstein 1: Serverkern ohne Oberfläche

- [x] Benutzer `owpanel-agent` und `sudo`-Regeln (Skript `setup-agent`, am 2026-11-06 auf der Debian-13-Test-VM zweimal erfolgreich gelaufen)
- [x] Paketquellen nginx.org und packages.sury.org mit festgelegten Schlüsseln (E-042, am 2026-11-08 auf der Test-VM bestanden)
- [x] Grundeinrichtung von NGINX, PHP-FPM, MariaDB, Certbot, nftables und Fail2ban (mit nginx.org, PHP 8.5 und nftables am 2026-11-08 auf der Test-VM zweimal erfolgreich gelaufen)
- [x] Prüfung, ob NGINX mit `http_v3_module` gebaut ist (am 2026-11-08 auf der Test-VM bestanden)
- [x] Standardserver mit TLS und QUIC, `quic_host_key` und UDP-Puffer (am 2026-11-08 auf der Test-VM bestanden)
- [x] Firewall-Regeln mit UDP 443 und automatischer Rücknahme bei Fehlern (E-044, Bestätigung und echte Rücknahme am 2026-11-08 auf der Test-VM bestanden)
- [x] Skript `site-create` mit vom Panel vorgegebenem Linux-Benutzer (mit `ow38217`, Zertifikat und HTTP/3 am 2026-11-08 auf der Test-VM bestanden; HTTP/3 auch von außen im Browser bestätigt)
- [x] Skript `site-delete` (am 2026-11-08 auf der Test-VM zweimal erfolgreich gelaufen; Benutzer, Dateien, Konfiguration und Zertifikat waren danach entfernt)
- [x] Zeitzone setzen mit `server-timezone` und Zeitabgleich per NTP (E-046, am 2026-11-08 auf der Test-VM bestanden)
- [x] Automatische Updates mit `unattended-upgrades` (E-047, am 2026-11-08 auf der Test-VM bestanden, einschließlich Probelauf)
- [x] ShellCheck und erste Skripttests (`tests/server.bats` und `tests/site.bats`)
- [x] Automatische Prüfung per GitHub Actions (`.github/workflows/server-scripts.yml`)

**Fertig, wenn:** Eine PHP-Website mit SSL entsteht per Skript auf einer frischen VM, ist von außen über HTTP/3 erreichbar, und ein zweiter Lauf beschädigt nichts.

## Meilenstein 2: Panel-Grundgerüst

- [x] Laravel-Projekt (Laravel 13 unter `panel/`, E-048; auf der Test-VM eingerichtet, Tests bestanden)
- [x] Tabler und Inter lokal einbinden
- [x] Layout und erste Blade-Komponenten
- [x] Anmeldung mit Passwort und Zwei-Faktor über Fortify, vorbereitet für Pocket ID als Alternative (E-050, E-056)
- [x] Schalter für hell und dunkel, am Konto gespeichert (E-055)
- [x] Kopfbereich wie goettlich.it, Logo und Favicon, Vor- und Nachname (E-057 bis E-059)
- [x] Farbige Kennzahl-Kacheln mit fester Bedeutung der Farben (docs/DESIGN.md)
- [x] Einheitlicher Stil mit getönten Kartenköpfen, Funktionskarten und Badges (E-060); Vor- und Nachname im Konto änderbar
- [x] Feste Kartenbreiten, Höchstbreite des Inhalts mittig im Fenster und Hinweise oben mittig (E-061, E-065)
- [x] Link zum Quellcode in der Oberfläche
- [x] Sprachen `de` und `en` (Umschalter, Browsersprache, Test auf gleiche Schlüssel; Sprache am Benutzerkonto folgt mit der Anmeldung)
- [x] Queue-Worker und `server_tasks` (E-052, E-063)
- [x] SSH-Anbindung mit `phpseclib` und festgelegtem Host-Schlüssel (E-051, E-062; am 2026-11-10 gegen einen lokalen sshd mit echter sudo-Regel geprüft, auch falscher Host-Schlüssel, abgelehnter Schlüssel und fehlende sudo-Regel)
- [x] Harmloses Testskript `panel-test` mit `sudo`-Regel und bats-Test
- [x] Aufgabenansicht mit live nachlaufendem Log (E-049) und Bereiche „Server“ und „Aufgaben“
- [x] Abnahme auf der Test-VM am 2026-11-10: Klick startet `panel-test`, Status und Log erscheinen, auch mit `--fail`
- [x] Audit-Log (E-053): Anmeldungen, Fehlversuche, Zwei-Faktor und Passwortänderungen, Ansicht nur für Administratoren
- [x] Rollen `admin` und `customer` sowie `customer_id` im Datenmodell vorsehen

**Fertig, wenn:** Ein Klick im Panel startet ein Testskript, und Status und Log erscheinen in der Oberfläche. Geprüft wird auf der Test-VM mit dem Panel unter dem Entwicklungsbenutzer `owdev` (E-054).

## Meilenstein 3: Standalone nutzbar

- [x] Installationsskript `install` im Terminal: Domain, Zeitzone und erster Administrator, Panel als `ow10000`, Queue-Worker als `owpanel-worker`, aus einem fertigen Archiv ohne Composer und Node.js auf dem Server (E-041, E-068 bis E-070)
- [x] GitHub Actions baut das Archiv mit Prüfsumme, bei Tags auch als GitHub-Release (`scripts/build-release`)
- [x] Startskript `get-owpanel` lädt Release oder Testbau, prüft die Prüfsumme und startet den Installer (E-071)
- [x] Abnahme am 2026-11-10 auf einer frischen Debian-13-VM mit eigener Domain: Installation mit `get-owpanel --dev`, Anmeldung mit Zwei-Faktor, Testskript, weitere Läufe mit neueren Archiven. Die Arbeitsweise mit `owdev` (E-054) entfällt.
- [x] Passwort zurücksetzen per E-Mail mit Mailversand über SMTP (E-056, E-094)
- [x] Eigenen Server automatisch als ersten Server anlegen (im Installer)
- [x] Websites anlegen und löschen im Panel (E-074), auf der Test-VM abgenommen am 2026-11-10: ohne Zertifikat und mit Zertifikat, Website über HTTP/3 erreichbar
- [x] Serverstatus und Auslastung: vollständiger Hostname, Online/Offline, CPU, RAM, Festplatte, Load und Netzwerk mit Diagrammen für 1 Stunde, 24 Stunden und 7 Tage (E-075); auf der Test-VM angesehen am 2026-11-10
- [x] Zertifikate: nachträglich anfordern, Ablaufdatum, Verlängerung und Warnung (E-076); „Zertifikat anfordern“ auf der Test-VM abgenommen am 2026-11-10
- [x] Datenbanken: anlegen, Passwort anzeigen und neu setzen, löschen (E-078), Datenbank-Werkzeug mit Tabellen, Inhalt und SQL-Konsole (E-079), Export und Import als SQL-Datei (E-082); auf der Test-VM abgenommen am 2026-11-11
- [x] Logs und Aufgaben je Website (E-086), Logs von Panel und Servern unter „Aufgaben & Logs“ (E-087); auf der Test-VM abgenommen am 2026-11-11
- [x] Cronjobs und SFTP je Website (E-088, E-089)
- [x] Firewall-Verwaltung: eigene Port-Regeln, fail2ban-Ausnahmen und Sperren (E-090)
- [ ] Hinweis im Panel, wenn ein Server neu gestartet werden muss, und Übersicht der ausstehenden und eingespielten Updates mit Neustart-Knopf (E-047, E-076); Übersicht auf der Test-VM angesehen am 2026-11-10, Neustart-Knopf abgenommen am 2026-11-11 (E-080: Hinweis verschwindet danach von selbst)
- [x] Links zu Impressum und Datenschutz des Anbieters in den Einstellungen, angezeigt in der Fußzeile (E-067, E-091)
- [x] Benutzerverwaltung: weitere Administratoren anlegen und Konten löschen, Start-Passwort mit Pflicht zur Änderung (E-081); auf der Test-VM abgenommen am 2026-11-11

**Fertig, wenn:** Auf einem frischen Debian-Server läuft nach einem Installationsbefehl ein typisches PHP-Projekt vollständig über das Panel.

## Meilenstein 4: Backups und Benachrichtigungen

- [x] Backup-Ziel festlegen: Hetzner-Snapshots und restic auf Storage Box oder S3 (E-095)
- [x] Hetzner-Snapshots über die API mit Zeitplan und Aufbewahrung (E-095)
- [x] Dateien und Datenbanken sichern: restic auf Hetzner Storage Box mit einem Subaccount je Server (E-096, E-097), Einstellungen je Server (E-100)
- [x] Panel selbst sichern: Datenbank, `.env`, Installer-Einstellungen und SSH-Schlüssel täglich; Wiederherstellung auf einem neuen Server über den Installer mit Auswahl der Sicherung (E-099); am 2026-11-12 auf einer zurückgesetzten Test-VM in der Hetzner Cloud wiederhergestellt
- [x] Zeitpläne: Backups täglich oder alle 12, 6, 3 oder 1 Stunden (E-103); alle 3 Stunden am 2026-11-13 abgenommen
- [x] Wiederherstellung einer Website: ganz, nur Dateien, nur Datenbanken oder einzelne Ordner, Dateien und Datenbanken, mit Einblick in die Sicherung (E-098); ganz und teilweise am 2026-11-13 abgenommen
- [x] Websites, die einem Server fehlen, aus den Sicherungen neu aufbauen, auch automatisch nach einem Panel-Restore im Installer, mit Firewall-Regeln und fail2ban-Ausnahmen (E-106); über das Panel einzeln und für alle am 2026-11-13 abgenommen, der Weg über den Installer folgt beim nächsten Restore
- [x] Benachrichtigungen per E-Mail und Web-Push (PWA, iOS und iPadOS ab 16.4, Android, Desktop) für Administratoren: Backup oder Snapshot fehlgeschlagen, Server nicht erreichbar, Neustart nötig, Festplatte fast voll, Zertifikat läuft ab; je Anlass und Weg wählbar, einmal plus Entwarnung, Ruhezeiten mit Nachlieferung (E-101); E-Mail und Push am 2026-11-12 auf dem iPhone bestätigt. Sicherheitsereignisse am eigenen Konto zurückgestellt
- [x] Weitere Benachrichtigungen: Backup, Snapshot und Wiederherstellung fertig, Server neu gestartet, Updates eingespielt, jede fehlgeschlagene Aufgabe (E-104); alle am 2026-11-13 per E-Mail und Push bestätigt
- [x] Nachgereicht: Passwort-Bestätigung alternativ mit Pocket ID (E-121, PR #61, abgenommen am 2026-11-15)
- [x] Nachgereicht: Zahl der offenen Warnungen als Kennzeichen am App-Icon (E-119, PR #58, abgenommen am 2026-11-15)
- [x] Oberfläche der Sicherungen: „Jetzt sichern“ für alle Websites und das Panel, Listen mit Live-Status, einheitliche Farben für Art, Ergebnis und Zustand (E-108), Passwort-Bestätigung 3 Stunden (E-107)
- [x] Installer: automatische Updates während der Installation pausieren, System vorher und am Ende aktualisieren, Neustart anbieten (E-102); Datenbank-Sitzung in UTC; am 2026-11-12 beim Restore erprobt

**Fertig, wenn:** Eine gesicherte Website und das Panel wurden erfolgreich auf einem frischen Server wiederhergestellt, und ein fehlgeschlagenes Backup meldet sich per E-Mail und Push. **Abgeschlossen am 2026-11-13:** Panel auf einer zurückgesetzten VM wiederhergestellt, die fehlenden Websites daraus neu aufgebaut (E-106), Websites ganz und teilweise zurückgespielt, „Backup fehlgeschlagen“ per E-Mail und Push erhalten. Noch nicht beobachtet, weil kein Anlass vorlag: das Verschwinden des Neustart-Hinweises nach einem nötigen Neustart (E-080); das folgt beim nächsten Kernel-Update.

Später, nicht zugesagt: weitere Backup-Ziele wie S3 und Snapshots bei anderen Anbietern (E-105); Subaccount löschen, wenn es eine Server-Löschung gibt.

## Meilenstein 4.1: Projekt-Website

Vorgezogen aus Meilenstein 8, damit es etwas Echtes für die nächsten Tests gibt (E-109).

- [x] Statische Website mit Vorstellung und Dokumentation auf Deutsch und Englisch im Design des Panels (`website/`), abgenommen am 2026-11-14
- [x] Domain festlegen: `owpanel.org` (2026-11-13); `base_url` der Website und `OWPANEL_HOMEPAGE_URL` des Panels zeigen darauf (E-073)
- [x] www-Adresse für Websites, beim Anlegen und nachträglich, mit Zertifikat für beide Namen (E-110), abgenommen am 2026-11-14 auf owpanel.org
- [x] Impressum und Datenschutz verlinken (Links auf goettlich.it in `website/site.json`)
- [x] Datenschutzerklärung für owpanel.org durchsehen: eigener Abschnitt für Projekt-Website und Dokumentation sowie Push-Benachrichtigungen des Panels, am 2026-11-14 auf goettlich.it/datenschutz veröffentlicht
- [x] Website als gewöhnliche Website in OWPanel betreiben, mit Zertifikat und HTTP/3 (2026-11-14)
- [x] Netzwerkverkehr je Netzwerkkarte in der Auslastung, z. B. öffentlich und privates Hetzner-Netzwerk (E-111), abgenommen am 2026-11-14
- [x] Domain einer Website ändern (E-112), abgenommen am 2026-11-14
- [x] Im Panel verweisen „OWPanel“ und „Dokumentation“ in der Fußzeile auf die Website, in der Sprache des Panels; abgenommen mit der laufenden Website

**Fertig, wenn:** Die Website läuft unter ihrer Domain auf einem Server mit OWPanel, und das Panel verlinkt auf ihre Dokumentation. **Abgeschlossen am 2026-11-14:** owpanel.org läuft auf OWPanel mit Zertifikat, HTTP/3 und www-Adresse, das Panel verlinkt auf die Dokumentation. Die Datenschutzerklärung deckt owpanel.org und die Push-Benachrichtigungen ab. Eine Weiterleitung von der alten Domain nach einem Domainwechsel ist verworfen (E-112).

## Meilenstein 6: Pocket ID

Abgeschlossen am 2026-11-14. Grundlage sind E-017, E-113 und E-114.

- [x] OIDC-Anbindung: Discovery, Authorization Code Flow mit PKCE, `state` und `nonce`, Prüfung des ID-Tokens mit `firebase/php-jwt` (E-113, #46)
- [x] Pocket ID als OIDC-Anbieter in den Einstellungen konfigurieren: Aussteller, Client-ID, Client-Secret, Name der Pocket-ID-Instanz, Anzeige der Redirect-URL (E-114, #46)
- [x] Panel-Benutzer (Administratoren und Kunden) mit Pocket-ID-Konto verknüpfen und trennen, im Audit-Log; Kundenkonten erst nach Freischaltung durch einen Administrator (E-114, #48)
- [x] Anmeldeseite mit Pocket ID als Alternative zu Passwort und Zwei-Faktor (#48)
- [x] Besprochen: kein Schalter zum Abschalten der Passwort-Anmeldung, jedes Konto behält sein Passwort (E-114)
- [x] Lokalen Notfallzugang testen: Anmeldung mit Passwort und Zwei-Faktor bleibt neben Pocket ID möglich (abgenommen am 2026-11-14)
- [x] Anleitung auf Deutsch und Englisch (Website-Dokumentation, Seite „Anmeldung mit Pocket ID“)

**Fertig, wenn:** Die Anmeldung über Pocket ID funktioniert, und bei einem Ausfall von Pocket ID bleibt das Panel über den Notfallzugang erreichbar.

## Meilenstein 6.1: DNS-Einträge über die Hetzner-API

Abgeschlossen am 2026-11-15 (PR #55 bis #62).

Grundlage sind E-115 und E-116. Nur für Administratoren.

- [x] Seite „DNS“ im Menü einer Website (PR #55, abgenommen am 2026-11-14): A und AAAA für Domain und `www` aus der Hetzner-Zone neben den Soll-Adressen des Servers
- [x] „DNS prüfen“ über die Nameserver der Zone und den Resolver des Panel-Servers, auch für Zonen außerhalb von Hetzner (PR #55, abgenommen am 2026-11-14)
- [x] Fehlende oder abweichende Einträge anlegen bzw. korrigieren, mit Rückfrage vor dem Überschreiben und Eintrag im Audit-Log (PR #57, abgenommen am 2026-11-15)
- [x] Zertifikat nachholen, wenn die Website ohne passende DNS-Einträge angelegt wurde und die Einträge danach stimmen (PR #60, abgenommen am 2026-11-15)
- [x] Nebenbei (PR #59): Fortschrittsanzeige beim Setzen, Kopierknöpfe in der Website-Übersicht, kein Fehlerfenster bei kurzen 502-Antworten; abgenommen am 2026-11-15
- [x] Nebenbei (PR #59): Audit-Log mit Detailseite je Eintrag; www-Adresse nur für Hauptdomains (E-120); abgenommen am 2026-11-15
- [x] Rückfrage beim Löschen einer Website als eigene Bestätigungsseite (zusammen mit der DNS-Frage) (PR #60, abgenommen am 2026-11-15)
- [x] Option „DNS-Einträge anlegen“ beim Anlegen einer Website (PR #60, abgenommen am 2026-11-15)
- [x] Rückfrage beim Löschen einer Website, ob die vom Panel angelegten Einträge mit gelöscht werden; fremde Einträge bleiben (PR #60, abgenommen am 2026-11-15)
- [x] Anleitung auf Deutsch und Englisch: eigene Seite „DNS“ auf owpanel.org, Startseite mit Multi-Server am Ende (PR #62, abgenommen am 2026-11-15)
- [x] Eigene Seiten für E-Mail-Versand, Pocket ID und API-Tokens unter System, optionaler Hetzner-Token für DNS (E-118, PR #56, abgenommen am 2026-11-15)

Auf dem Produktivsystem liegen die DNS-Zonen in einem anderen Hetzner-Projekt als die Server; dafür gibt es den eigenen DNS-Token (E-118). Die Zonen lassen sich später trotzdem ins Server-Projekt verschieben, dann wird der DNS-Token einfach entfernt.

**Fertig, wenn:** Eine neue Website in einer Hetzner-Zone ist ohne Handarbeit im DNS erreichbar, die Karte zeigt und prüft den Stand jeder Website, und nach dem Löschen bleiben nur die Einträge, die bleiben sollen.

## Meilenstein 7: Kundenbereich, Pakete und Sperren

Grundlage sind E-021, E-084, E-085 und E-123 bis E-130. Die API folgt in Meilenstein 7.1. Homepage und Panel bleiben getrennt und teilen die Anmeldung über Pocket ID (E-130).

- [x] Kunden anlegen, bearbeiten und löschen, Websites zuordnen (E-084, E-085, vorgezogen in Meilenstein 3, abgenommen am 2026-11-11)
- [x] System → „API-Tokens“ heißt „Externe Dienste“, Anleitung auf owpanel.org angepasst (E-123, PR #66, abgenommen am 2026-11-15)
- [x] Pakete unter Kunden → Pakete, Zuordnung je Kunde (E-125, PR #68, abgenommen am 2026-11-16)
- [x] Zugang für Kunden: anlegen, Einladungslink oder Start-Passwort, Einladung erneut senden, neues Start-Passwort, sperren, löschen (E-127, E-128, E-140)
- [x] Kunden in Pocket ID anlegen bzw. finden und verknüpfen, Anmeldelink für den Passkey, auf Wunsch gleich beim Anlegen des Zugangs (E-130, E-138, E-141, PR #77)
- [x] Kundenbereich mit eigener Navigation: „Meine Webseiten“ im Hauptmenü, „Mein Konto“ im Menü oben rechts (E-124); Grundgerüst mit der Liste der eigenen Webseiten (PR #74)
- [x] Policies prüfen die Zugehörigkeit über `customer_id`, mit Tests gegen Zugriffe auf fremde Daten für jede Kundenfunktion (E-124, E-143, E-144, E-145)
- [x] Website im Kundenbereich: Übersicht, www, Zertifikat anfordern, SFTP, Logs und DNS-Prüfung (E-124, E-143, PR #81, abgenommen am 2026-11-16)
- [x] Datenbanken mit Werkzeug, Export und Import sowie Cronjobs im Kundenbereich (E-124, E-144, E-146, PR #82, abgenommen am 2026-11-16)
- [x] Grenzen der Pakete durchsetzen: Webseiten, Datenbanken, Cronjobs und kürzester Cron-Abstand; Zuordnung Kunde, Administration oder nicht zugeordnet; Administratoren bestätigen Ausnahmen (E-125, E-139, PR #78)
- [x] Backups im Kundenbereich: ansehen, wiederherstellen, „Jetzt sichern“ mit Grenze je Tag (E-124, E-125, E-145, E-146, PR #83, abgenommen am 2026-11-16)
- [x] Speicherplatz: Quotas in der Grundeinrichtung, Skripte `site-usage` und `site-quota`, Berechnung je Kunde, Balken, Hinweise und Benachrichtigung, Übersicht für Administratoren (E-126, E-149, PR #87, abgenommen am 2026-11-17)
- [x] Skripte `site-suspend` und `site-unsuspend`, Sperren im Panel mit Notiz, Ansicht für Kunden, Zugang sperren (E-127, E-151, PR #89, abgenommen am 2026-11-18)
- [x] Anleitung auf Deutsch und Englisch: Kundenbereich, Pakete, Speicherplatz und Sperren stehen unter „Kunden und Benutzer“ (PR #89, abgenommen am 2026-11-18)

- [x] Nebenbei: Updates von Hand prüfen und installieren, Version des Panels mit Hinweis auf neue Releases (E-154, PR #92, abgenommen am 2026-11-19)
- [x] Nebenbei: Server → DNS mit Hostname (A/AAAA) und Reverse DNS, bei Hetzner änderbar (E-153, PR #91, abgenommen am 2026-11-18)
- [x] Nebenbei: Eine Handhabung für Serveraufgaben: Spinner am Knopf, Neuladen danach, kein Sprung auf die Aufgabenseite (E-152, PR #90, abgenommen am 2026-11-18)
- [x] Nebenbei: Installer fragt bei der ersten Installation nach dem Hostnamen (E-150, PR #88, abgenommen am 2026-11-17)
- [x] Nebenbei: System → Dienste mit Zustand der Worker und des Zeitplaners, Neustart, Anhalten und Fortsetzen (E-133, PR #70, abgenommen am 2026-11-16)
- [x] Nebenbei: Anrede (E-132, PR #69), „Webseite“ im Deutschen (E-134, PR #73), Kundenseite neu angeordnet (PR #72), Tabellen ohne Auge (E-131, PR #68)
- [x] Nebenbei: Protokoll der Push-Zustellungen je Gerät und im Log (E-147, PR #84, abgenommen am 2026-11-17)
- [x] Nebenbei: Besucherstatistik je Website unter System → Statistik, aus den Access-Logs, nur für Administratoren (E-148, PR #86, abgenommen am 2026-11-17)

**Fertig, wenn:** Ein Kunde meldet sich an, verwaltet seine Websites im Rahmen seines Pakets und sieht keine fremden Daten. Ein volles Paket hält Dateien und neue Datenbanken an und zeigt das dem Kunden. Eine gesperrte Website zeigt die Sperrseite, und nach dem Entsperren läuft sie wie vorher.

## Meilenstein 8: Öffentliche Version

- [ ] Update-Mechanismus für Panel und Serverskripte, erst nach dem ersten öffentlichen Release und gegen echte Releases; Testversionen werden bis dahin von Hand mit `get-owpanel --dev` eingespielt (E-155)
- [ ] Dokumentation auf Deutsch und Englisch (Grundstock seit Meilenstein 4.1 auf der Website, E-109); am 2026-11-19 und 2026-11-20 auf den Stand nach Sicherheitsprüfung und neuen Benachrichtigungen gebracht, mit Lizenz und Weg für Rückmeldungen (E-158, PR #95); am 2026-11-22 durchgesehen: Optionen des Installers, Größe des Servers, signierte Releases, Hinweise für weitere Server; am 2026-11-23 in „Für Administratoren“ und „Für Kunden“ geteilt, mit sieben Seiten für Kunden und „Hilfe“ im Kundenbereich (E-178)
- [x] Vollständiger Test von der Grundinstallation an auf einem frischen Server (am 2026-11-23 abgehakt, nach den Korrekturen aus PR #100 bis #107)
- [x] Lizenz bestätigt: AGPL-3.0-or-later, keine Bezahlfunktionen (E-158)
- [ ] Projekt-Homepage mit Vorstellung und Dokumentation (vorgezogen in Meilenstein 4.1)
- [x] Sicherheitsprüfung: Anfragelimits für alle sendenden Routen, private Dateien ohne Route, ungenutzter Code und Sprachschlüssel entfernt, Content Security Policy und HSTS für das Panel (E-156, E-157, PR #94, abgenommen am 2026-11-19)
- [x] Vor dem Produktivbetrieb: Passwort-Anmeldung per SSH für alle außer der Gruppe `owpanel-sftp` abschaltbar, nur wenn root und jedes Konto mit Anmeldung einen Schlüssel hat (Server → SSH, E-155, PR #93, abgenommen am 2026-11-19)
- [x] Vor dem Produktivbetrieb: Hinweis auf der SFTP-Seite, wenn sshd `AllowUsers` oder `AllowGroups` nutzt und `owpanel-sftp` darin fehlt (E-155, PR #93, abgenommen am 2026-11-19)
- [x] Benachrichtigungen für wartende Updates, ausgeschaltete automatische Updates und neue OWPanel-Versionen (E-159), dazu die Warnung für das Zertifikat des Panels (PR #96, abgenommen am 2026-11-20)
- [x] Benachrichtigungen für gestoppte Dienste, volle Storage Box, Anmeldung von neuem Gerät und Sperren nach Fehlversuchen, Heartbeat an eine Überwachung von außen (E-160, PR #96, abgenommen am 2026-11-20)
- [x] Multi-Server, API und Docker vor dem Release geklärt: erstes Release als Standalone, Reihenfolge danach (E-161 bis E-169); KONZEPT, ROADMAP und Website-Doku angepasst
- [x] Vorbereitung für Multi-Server: Releases in GitHub Actions signieren, Signatur in `get-owpanel` prüfen, Schlüssel im Installer hinterlegen (E-167, E-169); Schlüssel seit 2026-11-21 eingetragen, Secret gesetzt
- [x] Vorbereitung für Multi-Server: `server-status` meldet Version der Serverskripte und Fingerabdruck des Release-Schlüssels; Server → Zustand vergleicht mit dem Panel (E-167)
- [x] Sperrlisten in der Firewall: Adressen eintragen, Format prüfen, stündlich bis täglich aktualisieren, Benachrichtigung nach zwei Fehlversuchen, häufigste abgewiesene Adressen und Treffer je Liste (E-170)
- [x] Platzhalterseite für Websites ohne Indexdatei, eigene Seite unter `/etc/owpanel/site-welcome.html` möglich; der Installer schreibt bei jedem Lauf die Konfiguration aller Websites neu (E-171)
- [x] PHP 8.5 und 8.6 je Website wählbar, für Administratoren und Kunden; 8.6 erst als endgültige Version von sury.org (E-172)
- [x] PHP-Einstellungen je Website (Arbeitsspeicher, Upload, Laufzeit, Fehleranzeige) mit Höchstwerten und PHP-Prozessen im Paket; nginx-Upload-Grenze folgt der Einstellung (E-173)
- [x] fail2ban sperrt Scanner, die versteckte Dateien wie `.env` oder `.git` abrufen, für die Webports (E-174)
- [x] Schnellere Seiten: Laravel-Caches im Installer, Teile vom Server werden nachgeladen, Sperrlisten-Index im Cache (E-175)
- [x] gzip, Browser-Cache für statische Dateien und sichere Session-Cookies für Websites (E-176, PR #108, abgenommen am 2026-11-23)
- [x] Repository in die Organisation `goettlichit` verschoben, alle Verweise umgestellt (E-179)
- [ ] Externe Testnutzer
- [x] Versionierte Releases auf GitHub: Tag `vX.Y.Z` baut, signiert und veröffentlicht Archiv, `.sha256` und `.sig` (E-068, E-167); erste Version `v0.1.0` (E-180)
- [x] Frisches öffentliches Repository mit neuem Release-Schlüssel, altes als privates Archiv (E-181); Name bleibt OWPanel (E-182)
- [ ] Erstes öffentliches Release `v0.1.0`, mit der Prüfsumme von `get-owpanel` in der Anleitung (E-071, E-180)

## Meilenstein 5: Multi-Server mit dem Panel als Eingangstor

Am 2026-11-14 pausiert, am 2026-11-20 geklärt (E-161 bis E-168). Umgesetzt wird nach dem ersten öffentlichen Release und dem Update-Mechanismus (E-169). Der Panel-Server nimmt als Eingangstor (Gateway) alle Anfragen an und reicht sie über das private Hetzner-Netz an den Server der Website weiter. Lastverteilung heißt Platzierung; Ausfallsicherheit einzelner Websites gehört nicht dazu.

- [ ] Serveraufgaben laufen je Server nacheinander und für verschiedene Server parallel (mehrere Worker, Sperre je Server); die Statusabfrage läuft als Job je Server, damit ein ausgefallener Server die anderen nicht aufhält
- [ ] „Server hinzufügen“ mit Einmal-Token und `bash get-owpanel --join <Panel-Adresse>` (E-166)
- [ ] Steuerung über die private Adresse; Firewall der Website-Server: öffentlich nur Port 22, sonst nur das private Subnetz (E-166, E-168)
- [ ] Gateway: Zertifikate, ACME und HTTP/3 am Panel-Server, Weiterleitung an den Server der Website, echte Besucheradresse über `set_real_ip_from`, Grenzen wie Uploadgröße je Website (E-161, E-168)
- [ ] Eingangsadressen als Einstellung, eine IPv6 je Website aus Präfix und festem Teil (E-162)
- [ ] Serverauswahl beim Anlegen mit Vorschlag nach Reserve, erlaubte Server je Paket (E-161)
- [ ] Skriptversion abgleichen: `server-scripts-update` mit Signaturprüfung, keine ändernden Aufgaben bei abweichender Version, Meldung „Serverskripte veraltet“ (E-167)
- [ ] Backup-Subaccount beim Einbinden, Log des Gateways je Website, Zertifikatsprüfung auf dem Gateway, `/etc/letsencrypt` in der Panel-Sicherung (E-168)
- [ ] Wiederherstellung des Panels mit privatem Netz in `docs/WIEDERHERSTELLUNG.md`
- [ ] Anleitung auf Deutsch und Englisch

**Fertig, wenn:** Eine bestehende Standalone-Installation bekommt ohne Migration einen zweiten Server. Eine Website darauf ist über das Gateway mit Zertifikat und HTTP/3 erreichbar und per SFTP über den Hostnamen ihres Servers. Fällt der zweite Server aus, laufen Panel, Queue und die Websites der anderen Server weiter.

## Meilenstein 5.1: Websites umziehen

Grundlage sind E-161 und E-168.

- [ ] Website auf einen anderen Server umziehen: Dateien per rsync, Datenbanken, kurze Pause, Ziel im Gateway umstellen; das DNS bleibt unverändert
- [ ] Sicherungen ziehen mit: `restic copy` über einen vorübergehenden, nur lesenden Subaccount (eigene Entscheidung zu SICHERHEIT, Sicherungen, Regel 1)
- [ ] Server entfernen, wahlweise mit seinem Subaccount
- [ ] Ausfälle und abgelaufene Verbindungen behandeln: „Server neu einbinden“ mit neuem Token und bestätigtem neuen Host-Schlüssel, danach Neuaufbau (E-106)

**Fertig, wenn:** Eine Website zieht ohne DNS-Änderung auf einen anderen Server um, und ihre alten Sicherungen lassen sich dort ansehen und wiederherstellen.

## Meilenstein 9: Docker-Apps

Grundlage ist E-163. Nur für Administratoren.

- [ ] Docker je Server einschalten; Verhalten der Firewall mit veröffentlichten Ports auf einer Test-VM prüfen
- [ ] App anlegen: Name, Server, Domain, Compose-Datei; Ports nur auf `127.0.0.1` oder der privaten Adresse, Daten nur unter `/srv/owpanel/apps/<name>/`
- [ ] Gateway leitet an Server und Port der App weiter, mit Einstellungen wie WebSockets und Uploadgröße
- [ ] Starten, Stoppen, Logs und neue Images
- [ ] Sicherung des App-Verzeichnisses mit restic, Umzug wie in Meilenstein 5.1
- [ ] Anleitung auf Deutsch und Englisch, auch für den Umstieg vom Nginx Proxy Manager

**Fertig, wenn:** Pocket ID läuft als App auf einem Server, ist über das Gateway mit Zertifikat und HTTP/3 erreichbar, und der Nginx Proxy Manager wird nicht mehr gebraucht.

## Meilenstein 7.1: API für die Homepage

Auf Wunsch des Entwicklers hinter Meilenstein 8 gesetzt (E-155) und am 2026-11-20 auf die Anbindung der eigenen Homepage verkleinert (E-165, beantwortet F-025). Die API kennt keine Server und hängt deshalb nicht von Meilenstein 5 ab; sie kann bei Bedarf vorgezogen werden (E-169). Die Nummer bleibt wegen der Verweise.

- [ ] API-Tokens mit Rechten unter System → Externe Dienste (E-123)
- [ ] Lesen: Kunden mit Panel-Kundennummer und `external_id`, Pakete, Websites mit Status und Verbrauch
- [ ] Schreiben: Zahlungshinweis je Kunde setzen und entfernen; das Panel zeigt ihn beim Kunden und in der Übersicht
- [ ] API-Dokumentation auf Deutsch und Englisch
- [ ] Anbindung der goettlichIT-Homepage als erster Praxistest: Übersicht der Websites mit Leserechten, „Verwalten“ verweist ins Panel (E-130)

Später, mit eigener Entscheidung: Websites über die API anlegen und sperren, Webhooks, Pocket ID für Kunden über die API (E-130).

**Fertig, wenn:** Die Homepage zeigt einem Kunden seine Websites mit Paket und Status und setzt einen Zahlungshinweis, den das Panel anzeigt.

## Ideen für später

Noch nicht zugesagt:

- Server → Dienste: „Neu laden“ und „Neu starten“ für NGINX und PHP-FPM über ein freigegebenes Skript `server-service` (E-133); den Zustand der Dienste zeigt Server → Zustand seit E-160
- Zweites Gateway mit keepalived und Floating IPs (E-164)
- Eigene ausgehende IPv6 je Website über SNAT nach Linux-Benutzer (E-162)
- Server bei anderen Anbietern über WireGuard statt privatem Hetzner-Netz (E-166)
- Server direkt aus dem Panel über die Hetzner-API und cloud-init anlegen (E-166)
- Port-Falle in nftables: Wer ungenutzte Ports wie 23, 3389 oder 3306 anspricht, landet für einige Stunden auf einer Sperrliste
- Honeypot64 von ipv64.net als Docker-App auf einem Server, der nicht Gateway ist, mit eigener Adresse
- Prüfung jeder Website von außen
- Eigener Agent für Server hinter NAT
- Git-Deployment
- Mehrere Benutzerkonten pro Kunde
- Harte Grenzen für CPU und Arbeitsspeicher je Website über cgroups (eigener PHP-FPM-Dienst je Website; `memory_limit` und PHP-Prozesse gibt es mit E-173)
- Weitere Rollen und Rechte
- Python-Websites
- WordPress-Installation
- Pocket ID als optional installierbarer Dienst (mit Meilenstein 9 als Docker-App)
- Weitere DNS-Anbieter neben Hetzner (etwa Cloudflare, INWX, deSEC) für Anlegen und Korrigieren auf der DNS-Seite; die Prüfung über die Nameserver funktioniert schon mit jedem Anbieter (Idee vom 2026-11-15)
- Auswertung von Firewall und Fail2ban im Panel: blockierte Verbindungen und Verlauf (gesperrte Adressen und Entsperren gibt es seit E-090)
