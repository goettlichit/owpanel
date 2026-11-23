---
title: Webseiten
description: Webseiten anlegen, Zertifikate, SFTP, Cronjobs und Logs.
group: Betrieb
icon: world-www
slug: websites
---

## Webseite anlegen

Unter **Webseiten → Neue Webseite** gibst du die Domain ein, etwa `shop.example.de`, und wählst, ob ein Zertifikat angefordert wird (Standard: ja). Domains mit Umlauten sind erlaubt; das Panel wandelt sie selbst um.

Das Panel richtet dann im Hintergrund ein:

- einen eigenen **Linux-Benutzer** mit zufälliger Nummer, etwa `ow38217`
- einen eigenen **PHP-FPM-Pool** in der gewählten PHP-Version, standardmäßig PHP 8.5
- die **NGINX-Konfiguration**
- ein **Zertifikat** von Let's Encrypt mit **HTTP/3** über QUIC, HTTP/2 und HTTP/1.1 als Rückfall

::: info
Damit das Zertifikat klappt, muss der DNS-Eintrag der Domain schon auf den Server zeigen. Liegt die Domain bei Hetzner, legt das Panel die Einträge mit **DNS-Einträge bei Hetzner anlegen** selbst an; mehr dazu unter [DNS](docs:dns). Schlägt die Anfrage fehl, bleibt die Webseite über HTTP erreichbar, und du forderst das Zertifikat später mit **Zertifikat anfordern** erneut an.
:::

## PHP-Version

Jede Webseite läuft mit PHP 8.5 oder PHP 8.6. Ältere Versionen bietet OWPanel bewusst nicht an. Neue Webseiten bekommen PHP 8.5, beim Anlegen kannst du eine andere Version wählen.

Umstellen kannst du die Version im Menü der Webseite unter **PHP** in der Karte **PHP-Version**; Kunden finden denselben Menüpunkt bei ihrer Webseite. Die Webseite ist dabei etwa eine Sekunde nicht erreichbar. Cronjobs nutzen danach ebenfalls die neue Version, wenn sie `php` aufrufen. Prüfe nach dem Umstellen, ob die Webseite wie gewohnt läuft.

Das Panel bietet nur Versionen an, die auf dem Server installiert sind; welche das sind, steht unter **Server → Zustand**. Eine neue Version wie PHP 8.6 installiert der Installer erst, wenn sie als endgültige Version und nicht nur als Vorabversion bereitsteht. Ist sie es noch nicht, führe den Installer später einfach erneut aus.

## PHP-Einstellungen

Ebenfalls unter **PHP** stellst du in der Karte **PHP-Einstellungen** ein, wie viel Arbeitsspeicher ein Aufruf der Webseite bekommt, wie groß eine hochgeladene Datei höchstens sein darf und wie lange ein Aufruf höchstens läuft. Dazu kommt, ob Fehler im Browser erscheinen; schalte das nur zur Fehlersuche ein. Ohne Änderung gelten 256 MB, 64 MB und 60 Sekunden.

Die Höchstwerte kommen aus dem Paket des Kunden, ebenso die Zahl der PHP-Prozesse, die gleichzeitig für die Webseite laufen. Die Karte zeigt, was erlaubt ist. Kunden ändern die Werte unter **PHP** bei ihrer Webseite; ohne Paket bleiben die Standardwerte. Die Werte stehen fest in der PHP-Konfiguration der Webseite: Eine `.user.ini` oder `ini_set()` kann sie nicht erhöhen. Andere Einstellungen, etwa die Zeitzone, lassen sich weiter über eine `.user.ini` im Ordner der Webseite setzen.

## Komprimierung, Browser-Cache und Session-Cookies

Damit Webseiten schneller laden, komprimiert NGINX Text wie HTML, CSS, JavaScript, JSON und SVG mit gzip. Browser dürfen CSS und JavaScript 7 Tage behalten, Bilder, Schriften, Videos und PDF 30 Tage. Ändert sich eine Datei öfter, hänge eine Version an den Link, etwa `style.css?v=2`, dann holt der Browser sie sofort neu.

PHP-Sessions bekommen sichere Cookies: Sie sind für JavaScript nicht lesbar (`HttpOnly`), werden nicht bei Anfragen von fremden Seiten mitgeschickt (`SameSite=Lax`) und, sobald die Webseite ein Zertifikat hat, nur über HTTPS übertragen (`Secure`). Braucht eine Anwendung andere Werte, etwa `SameSite=None` für eine Rückkehr von einem Zahlungsanbieter per POST, setzt sie diese selbst mit `session_set_cookie_params()` oder in einer `.user.ini`.

## Platzhalterseite

Solange im Ordner `htdocs/public` weder eine `index.php` noch eine `index.html` liegt, zeigt die Startseite eine schlichte Platzhalterseite mit der Domain und dem Hinweis, dass hier eine neue Webseite entsteht, auf Deutsch und Englisch. Sobald eine Indexdatei hochgeladen ist, verschwindet sie von selbst; löscht der Kunde später alles, erscheint sie wieder. Andere Ordner ohne Indexdatei antworten weiter mit 403.

Eine eigene Platzhalterseite legst du als `/etc/owpanel/site-welcome.html` auf dem Server ab; `@DOMAIN@` darin ersetzt das Panel durch die Domain der Webseite. Sie gilt für neue Webseiten sofort und für bestehende, sobald ihre Konfiguration neu geschrieben wird, spätestens beim nächsten Lauf des Installers.

## Auch unter www erreichbar

Mit **Auch unter www erreichbar** beim Anlegen, oder später mit **www-Adresse einschalten** unter **Webseite und Domain**, antwortet die Webseite zusätzlich unter `www.<Domain>`. Wer diese Adresse aufruft, landet dauerhaft (301) auf der Domain, mit Pfad und Parametern. Die Option gibt es nur für Hauptdomains wie `owpanel.org` oder `example.co.uk`; dort ist sie vorausgewählt. Für Subdomains wie `shop.example.de` ist sie ausgegraut.

1. Lege im DNS für `www.<Domain>` einen A- bzw. AAAA-Eintrag auf denselben Server an.
2. Schalte die www-Adresse ein. Hat die Webseite ein Zertifikat, fordert das Panel sofort ein neues an, das beide Namen abdeckt.

Fehlt der DNS-Eintrag noch, bekommt die Domain allein ihr Zertifikat, und `www` leitet vorerst nur über HTTP weiter. Mit **Zertifikat neu anfordern** holst du das nach, sobald der Eintrag stimmt. Schaltest du die www-Adresse aus, fordert das Panel ein Zertifikat nur für die Domain an.

## Domain ändern

Gefällt dir die Domain nicht mehr, ziehst du die Webseite unter **Webseiten → Webseite → Webseite und Domain** auf eine neue um. Dateien, Datenbanken, SFTP, Cronjobs und Backups bleiben, wie sie sind, denn sie gehören zum Benutzer der Webseite und nicht zur Domain.

1. Lege im DNS einen A- bzw. AAAA-Eintrag für die neue Domain auf den Server an, bei eingeschalteter www-Adresse auch für `www.<neue Domain>`.
2. Trage die neue Domain ein und klicke auf **Domain ändern**.

Hat die Webseite ein Zertifikat, fordert das Panel zuerst eines für die neue Domain an. Bis dahin bleibt die Webseite unter der alten Domain erreichbar. Lehnt Let's Encrypt die neue Domain ab, zum Beispiel weil der DNS-Eintrag noch fehlt, bleibt alles beim Alten, und du kannst es später noch einmal versuchen. Danach ist die alte Domain nicht mehr erreichbar.

## Wo die Dateien liegen

Jede Webseite hat ihren eigenen Ordner, den andere Webseiten nicht lesen können:

| Pfad | Inhalt |
|---|---|
| `htdocs/` | Deine Dateien |
| `htdocs/public/` | Dokumentwurzel, also was der Browser sieht |
| `tmp/` | Temporäre Dateien von PHP |

Die Webseite liegt unter `/var/www/<Benutzer>/`. Ihre Zugriffs- und Fehlerlogs schreibt NGINX nach `/var/log/nginx/`; im Panel findest du sie unter **Logs**.

Die Dokumentwurzel `htdocs/public/` passt direkt zu Laravel und vielen anderen Frameworks. Bei einfachen Webseiten legst du die Dateien einfach dort ab.

## SFTP

Unter **Webseite → SFTP** schaltest du den Zugang ein. Der Benutzername ist der Linux-Benutzer der Webseite. Du kannst ein Passwort erzeugen lassen, SSH-Schlüssel hinterlegen oder beides.

- Der Zugang kann nur SFTP: keine Shell, keine Weiterleitungen.
- Er sieht nur den Ordner der Webseite und startet in `htdocs`.
- Das Anzeigen eines Passworts steht im Audit-Log.
- Als Server zeigt das Panel die Domain, sobald ihr DNS auf diesen Server zeigt. Zeigt sie noch woandershin, etwa während eines Umzugs, steht dort die IP-Adresse des Servers, die sofort funktioniert. Das Panel prüft das bei jedem Aufruf der Seite, das Ergebnis gilt fünf Minuten.
- Administratoren, die lieber auf der Kommandozeile arbeiten, melden sich per SSH als root an und wechseln mit `runuser -l <Benutzer> -s /bin/bash` in den Benutzer der Webseite. Den passenden Befehl zeigt die SFTP-Seite an.

## E-Mails verschicken

OWPanel hat keinen Mailserver und gibt auch keine Mails der Webseiten weiter. Die PHP-Funktion `mail()` schlägt deshalb fehl. Webseiten verschicken Mails direkt über SMTP an einen Mailserver, mit eigenem Postfach und Passwort. Wie das bei WordPress, Frameworks und anderen Anwendungen geht, steht in der Kunden-Doku unter [E-Mails verschicken](docs:customer-websites#e-mails-verschicken); dorthin kannst du auch deine Kunden schicken.

Warum es keine Weiterleitung über OWPanel gibt: Dafür müssten alle Webseiten über ein gemeinsames Konto senden. Eine gehackte Webseite könnte dann über dieses Konto Spam verschicken und es auf Sperrlisten bringen, samt den Benachrichtigungen des Panels. Mit eigenen Zugangsdaten je Webseite trifft ein Missbrauch nur deren Postfach.

## Cronjobs

Unter **Webseite → Cronjobs** legst du Aufgaben an, die regelmäßig laufen, etwa `php artisan schedule:run` jede Minute. Der Zeitplan hat fünf Felder wie in crontab: Minute, Stunde, Tag, Monat, Wochentag. Jobs laufen als Benutzer der Webseite im Ordner `htdocs`. Ihre Ausgaben stehen unter **Logs → Cron**.

## Logs

Unter **Webseite → Logs** siehst du Zugriffe, Fehler und PHP-Meldungen der Webseite, unter **Server → Logs** die des Servers: Anmeldungen, fail2ban, NGINX und das System-Journal.

## Webseite löschen

**Webseite löschen**, der letzte Punkt im Menü der Webseite, führt auf eine Bestätigungsseite. Gelöscht werden Dateien, Logs, Datenbanken, Zertifikat, PHP-FPM-Pool, NGINX-Konfiguration und der Linux-Benutzer, endgültig. Zur Bestätigung gibst du die Domain ein. Hat das Panel DNS-Einträge für die Webseite angelegt, zeigt die Seite sie an; mit **Die vom Panel angelegten DNS-Einträge mitlöschen** entfernt es die, die noch genau auf diesen Server zeigen. Geänderte und fremde Einträge bleiben. Sicherungen auf der Storage Box löscht das Panel nicht, im Panel sind sie danach aber nicht mehr erreichbar. Brauchst du die Daten noch, exportiere vorher die Datenbanken und lade die Dateien per SFTP herunter.
