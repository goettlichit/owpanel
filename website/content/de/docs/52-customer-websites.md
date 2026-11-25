---
title: Ihre Webseite
description: Zertifikat, www-Adresse, DNS, Ordner, SFTP und E-Mail-Versand.
audience: customer
icon: world-www
slug: customer-websites
---

## Zertifikat und HTTPS

Jede Webseite bekommt ein kostenloses Zertifikat von Let's Encrypt. Damit ist sie über `https://` erreichbar, auch über das schnelle HTTP/3. Das Zertifikat verlängert sich von selbst.

Dafür muss die Domain auf den Server zeigen. Fehlt das Zertifikat noch, prüfen Sie zuerst unter **DNS**, ob alle Einträge stimmen, und klicken dann in der **Übersicht** auf **Zertifikat anfordern**.

## Auch unter www erreichbar

Bei Hauptdomains wie `example.de` können Sie in der **Übersicht** die www-Adresse einschalten. Wer `www.example.de` aufruft, landet dann auf `example.de`.

1. Legen Sie bei Ihrem DNS-Anbieter für `www` dieselben Einträge an wie für die Domain. Die Adressen zeigt die Seite **DNS**.
2. Schalten Sie die www-Adresse ein. Das Zertifikat wird dabei um die www-Adresse erweitert.

Fehlt der DNS-Eintrag noch, funktioniert `www` vorerst nur ohne HTTPS. Sobald der Eintrag stimmt, fordern Sie das Zertifikat erneut an.

## DNS prüfen

Ihre Domain verwalten Sie weiter bei Ihrem DNS-Anbieter. Die Seite **DNS** zeigt, welche Adressen die Einträge A (IPv4) und AAAA (IPv6) haben müssen („Soll“) und was Ihr DNS-Anbieter gerade ausliefert. Ändern können Sie die Einträge dort nicht. Nach einer Änderung bei Ihrem DNS-Anbieter dauert es meist wenige Minuten, manchmal einige Stunden, bis alle Besucher die neue Adresse bekommen. **DNS prüfen** fragt sofort neu nach.

## Ordner der Webseite

| Ordner | Inhalt |
|---|---|
| `htdocs/` | Ihre Dateien |
| `htdocs/public/` | was Besucher im Browser sehen |
| `tmp/` | temporäre Dateien von PHP |

Bei einer einfachen Webseite legen Sie alles in `htdocs/public/`. Anwendungen wie Laravel oder Symfony, die einen eigenen Ordner `public` mitbringen, laden Sie nach `htdocs/`; dann passt ihr `public` genau. So liegen Konfiguration und Code außerhalb dessen, was Besucher abrufen können.

Solange in `htdocs/public/` weder `index.php` noch `index.html` liegt, zeigt die Webseite eine Platzhalterseite. Sie verschwindet, sobald Sie Ihre Startseite hochladen.

Dateien, deren Name mit einem Punkt beginnt, etwa `.env` oder `.git`, liefert der Server nie aus. Wer massenhaft danach sucht, wird gesperrt.

::: info
Der Server ist NGINX, nicht Apache. Eine `.htaccess` wirkt deshalb nicht. Die üblichen schönen Adressen von WordPress, Laravel und ähnlichen Anwendungen funktionieren trotzdem: Alles, was keine Datei ist, geht an die `index.php`. Brauchen Sie eigene Weiterleitungen, fragen Sie Ihren Anbieter.
:::

## Dateien per SFTP

Unter **SFTP** schalten Sie den Dateizugang ein. Dort stehen Server, Port und Benutzername. Sie können ein Passwort erzeugen lassen und anzeigen, einen SSH-Schlüssel hinterlegen oder beides. Jede Anzeige des Passworts wird protokolliert.

Verbinden Sie sich mit einem SFTP-Programm wie FileZilla, WinSCP oder Cyberduck. Der Zugang sieht nur den Ordner Ihrer Webseite und startet in `htdocs`. Eine Kommandozeile gibt es nicht. Brauchen Sie den Zugang gerade nicht, schalten Sie ihn aus.

### Von der Kommandozeile

Pfade sind aus Sicht des Zugangs angegeben: Das Webroot ist `/htdocs/public`. Statt `server` und `owNNNNN` setzen Sie die Angaben von der Seite **SFTP** ein.

- **Einzelne Dateien oder ein erster Upload** mit `scp` (OpenSSH ab Version 9, auf macOS und aktuellem Linux vorhanden):
  ```sh
  scp -r ./meine-seite/* owNNNNN@server:/htdocs/public/
  ```
- **Wiederholt abgleichen**, nur geänderte Dateien, mit `lftp`:
  ```sh
  lftp -u owNNNNN, sftp://server -e "mirror -R --only-newer ./meine-seite/ /htdocs/public/; bye"
  ```
  `--dry-run` zeigt vorher, was übertragen würde; `--delete` löscht auf dem Server, was lokal fehlt.
- **`rclone`** geht ebenfalls, mit dem Ziel `:sftp:/htdocs/public` und `copy` statt `sync`, wenn auf dem Server nichts gelöscht werden soll.

`rsync` funktioniert nicht, weil es auf dem Server eine Kommandozeile bräuchte. Meldet `scp` „Connection closed“, nutzt es noch das alte Protokoll: Lassen Sie die Option `-O` weg oder setzen Sie `-s`.

## Schneller und sicherer, ohne Ihr Zutun

- Texte wie HTML, CSS und JavaScript werden komprimiert übertragen.
- Browser behalten CSS und JavaScript 7 Tage, Bilder, Schriften und Videos 30 Tage. Ändern Sie eine Datei oft, hängen Sie eine Version an den Link, etwa `style.css?v=2`; dann holt der Browser sie sofort neu.
- PHP-Sitzungen bekommen sichere Cookies: für JavaScript nicht lesbar, nicht bei Anfragen von fremden Seiten mitgeschickt, und mit Zertifikat nur über HTTPS. Braucht Ihre Anwendung andere Werte, etwa für die Rückkehr von einem Zahlungsanbieter, setzt sie diese selbst.

## E-Mails verschicken

Ihre Webseite verschickt E-Mails nicht über den Webserver, sondern direkt über SMTP an einen Mailserver, mit einem eigenen Postfach und Passwort. Die PHP-Funktion `mail()` funktioniert nicht. So richten Sie es ein:

- **WordPress:** ein SMTP-Plugin, etwa *WP Mail SMTP* oder *FluentSMTP*.
- **Laravel, Symfony und andere Frameworks:** die SMTP-Einstellungen in der `.env` (`MAIL_MAILER=smtp`, Host, Port, Benutzer, Passwort).
- **Shop-, Abrechnungs- und Ticketsysteme:** fast immer eine SMTP-Einstellung in der Verwaltung der Anwendung.
- **Eigenes PHP:** eine Bibliothek wie PHPMailer oder Symfony Mailer statt `mail()`.

Nutzen Sie Port 587 mit STARTTLS; die Ports 25 und 465 sind oft gesperrt. Damit Mails nicht im Spam landen, muss der SPF-Eintrag Ihrer Absenderdomain den Mailserver erlauben, und der Mailserver sollte mit DKIM signieren. Die Zugangsdaten bekommen Sie von Ihrem Mail-Anbieter.
