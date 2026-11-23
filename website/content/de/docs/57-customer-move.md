---
title: Webseite umziehen
description: Eine bestehende Webseite von einem anderen Anbieter übernehmen.
audience: customer
icon: truck-delivery
slug: customer-move
---

So ziehen Sie eine Webseite von Ihrem bisherigen Anbieter um, ohne dass Besucher etwas merken. Die alte Webseite läuft dabei weiter, bis alles fertig ist.

## Vorbereiten

1. **TTL senken:** Stellen Sie bei Ihrem DNS-Anbieter einen Tag vorher die TTL der Einträge A und AAAA auf 300 Sekunden. Dann wirkt die spätere Umstellung in wenigen Minuten.
2. **Webseite anlegen lassen:** Ihr Anbieter legt die Webseite mit Ihrer Domain an, zunächst ohne Zertifikat, weil die Domain noch auf den alten Server zeigt.
3. **Bestandsaufnahme beim alten Anbieter:** Notieren Sie PHP-Version, Datenbanken, Cronjobs und eigene Weiterleitungen, etwa aus einer `.htaccess`.

## Dateien und Datenbank übertragen

1. Laden Sie die Dateien beim alten Anbieter herunter und per [SFTP](docs:customer-websites#dateien-per-sftp) nach `htdocs/public/` hoch. Bringt Ihre Anwendung einen eigenen Ordner `public` mit, wie Laravel, laden Sie sie nach `htdocs/`.
2. Stellen Sie unter [PHP](docs:customer-php) die passende Version und die nötigen Einstellungen ein, etwa die Upload-Größe.
3. Exportieren Sie die Datenbank beim alten Anbieter, legen Sie unter [Datenbanken](docs:customer-databases) eine neue an und importieren Sie den Export.
4. Tragen Sie in der Konfiguration Ihrer Anwendung die neuen Zugangsdaten der Datenbank ein, mit Host `localhost`. Passen Sie Pfade an, falls die Anwendung feste Pfade gespeichert hat.
5. Legen Sie die [Cronjobs](docs:customer-cron) neu an.
6. Stellen Sie den E-Mail-Versand auf [SMTP](docs:customer-websites#e-mails-verschicken) um, falls Ihre Anwendung bisher `mail()` nutzt.

## Vorher testen

Sie können die neue Webseite ansehen, bevor die Domain umzieht. Tragen Sie dazu auf Ihrem Computer die neue Adresse in die Datei `hosts` ein. Die Adresse steht unter **DNS** als „Soll“.

- Windows: `C:\Windows\System32\drivers\etc\hosts`, als Administrator bearbeiten
- macOS und Linux: `/etc/hosts`, mit `sudo` bearbeiten

```
203.0.113.10  example.de www.example.de
```

Ihr Browser ruft die Domain dann auf dem neuen Server auf, alle anderen weiter auf dem alten. Nehmen Sie den Eintrag nach dem Test wieder heraus.

## Umstellen

1. Bei Shops und anderen Webseiten mit laufenden Bestellungen oder Anmeldungen: Setzen Sie die alte Webseite in den Wartungsmodus. Übertragen Sie dann die Datenbank noch einmal und, falls sich Dateien geändert haben, auch diese.
2. Ändern Sie bei Ihrem DNS-Anbieter die Einträge A und AAAA auf die Adressen unter **DNS**.
3. Sobald **DNS** alles grün zeigt, klicken Sie in der **Übersicht** auf **Zertifikat anfordern**.
4. Prüfen Sie die Webseite, Formulare und den E-Mail-Versand.

Lassen Sie die alte Webseite noch einige Tage unverändert stehen, bevor Sie sie kündigen. Dann können Sie bei einem Problem schnell zurück. Danach stellen Sie die TTL wieder auf den alten Wert.
