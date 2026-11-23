# OWPanel – Panel

Die Laravel-Anwendung von OWPanel. Überblick und Regeln stehen in der [README des Projekts](../README.md) und unter [docs/](../docs/).

## Voraussetzungen für die Entwicklung

- PHP 8.4 oder neuer (auf den Servern läuft 8.5) mit `mbstring`, `intl` und `pdo_mysql` oder `pdo_sqlite`
- Composer 2
- Node.js 20 oder neuer und npm (Debian 13 bringt Node.js 20 mit), nur zum Bauen von CSS und JavaScript. Auf den Servern läuft kein Node.js (E-048).
- MariaDB oder für einen schnellen Start SQLite

## Lokal starten

```sh
cd panel
cp .env.example .env          # Datenbank eintragen, für SQLite siehe Kommentar in der Datei
composer install
php artisan key:generate
php artisan migrate
php artisan owpanel:create-admin   # erster Administrator, fragt Vor- und Nachname, E-Mail und Passwort
npm ci
composer dev                  # startet Webserver, Queue-Worker und Vite zusammen
```

Beim ersten Anmelden richtet der Administrator die Zwei-Faktor-Authentifizierung ein. Dafür braucht es eine Authenticator-App.

Das Panel ist danach unter <http://localhost:8000> erreichbar.

`composer dev` startet drei Prozesse, die man auch einzeln in eigenen Terminals starten kann:

| Befehl | Aufgabe |
|---|---|
| `php artisan serve` | Eingebauter Webserver von PHP |
| `php artisan queue:listen --tries=1` | Führt Hintergrundjobs aus, später etwa Serverskripte |
| `npm run dev` | Vite: baut CSS und JavaScript bei jeder Änderung neu |

## Server anbinden

Das Panel steuert Server nur per SSH als `owpanel-agent`, auch den eigenen über `127.0.0.1` (E-005, E-051). Dafür braucht es einen eigenen Schlüssel und den Host-Schlüssel des Servers:

```sh
php artisan owpanel:ssh-key        # erzeugt storage/app/private/ssh/id_ed25519, gibt den öffentlichen Schlüssel aus
# auf dem Server als root, mit dem ausgegebenen öffentlichen Schlüssel in panel.pub:
#   server/provision/setup-agent --public-key-file panel.pub
php artisan owpanel:add-server --name=web1 --host=127.0.0.1 \
    --host-key-file=/etc/ssh/ssh_host_ed25519_key.pub
```

Danach startet unter „Server“ der Knopf „Testskript starten“ das harmlose Skript `panel-test`. Ohne laufenden Queue-Worker (`php artisan queue:work`) bleibt die Aufgabe auf „Wartend“.

Livewire ist ein Laravel-Paket für interaktive Seiten in PHP und Blade: Die Komponente wird auf dem Server gerendert, ihr JavaScript tauscht nur die geänderten Teile der Seite aus. Livewire liefert sein Skript selbst aus, es kommt nicht von einem CDN.

## Prüfen

```sh
npm run build                       # die Tests brauchen die gebauten Assets
php artisan test                    # Tests
vendor/bin/pint                     # Code-Stil korrigieren
node scripts/check-licenses.mjs     # Lizenzen aller Abhängigkeiten
composer audit && npm audit         # bekannte Sicherheitslücken
```

Dieselben Prüfungen laufen in GitHub Actions (`.github/workflows/panel.yml`).

## Aufbau

| Pfad | Inhalt |
|---|---|
| `app/Http/Middleware/SetLocale.php` | Wählt die Sprache: Konto, Sitzung, Browser, dann `APP_LOCALE` |
| `app/Http/Middleware/EnsureTwoFactorForAdmins.php` | Schickt Administratoren ohne Zwei-Faktor zur Einrichtung |
| `app/Providers/FortifyServiceProvider.php` | Anmeldung über Fortify: eigene Ansichten, Grenzen für Fehlversuche |
| `app/Listeners/RecordAuthenticationEvents.php` | Schreibt Anmeldungen und Änderungen an der Sicherheit ins Audit-Log |
| `app/Support/Audit.php` | `Audit::record('aktion', $objekt)` für neue Einträge im Audit-Log |
| `app/Console/Commands/CreateAdmin.php` | `php artisan owpanel:create-admin` |
| `app/Support/SourceCode.php` | Link zum Quellcode der laufenden Version (AGPL, Abschnitt 13) |
| `config/owpanel.php` | Version, Quellcode-Adresse, Sprachen |
| `lang/de/`, `lang/en/` | Alle sichtbaren Texte. Ein Test prüft, dass beide Sprachen dieselben Schlüssel haben. |
| `resources/views/components/` | Blade-Komponenten auf Basis von Tabler |
| `resources/css/app.css` | Bindet Tabler, Tabler Icons und Inter aus `node_modules` ein |
| `public/js/theme.js` | Setzt System, Hell oder Dunkel, bevor die Seite gezeichnet wird |
| `scripts/check-licenses.mjs` | Prüft die Lizenzen aller Abhängigkeiten anhand der Lock-Dateien |
| `app/Enums/ServerScript.php` | Positivliste der Serverskripte mit Zeitlimit (E-051). Ein Test vergleicht sie mit den `sudo`-Regeln. |
| `app/Servers/` | Skriptaufruf mit escapten Parametern, Host-Schlüssel, Ergebnis, SSH mit `phpseclib` |
| `app/Jobs/RunServerTask.php` | Queue-Job, der eine Serveraufgabe genau einmal ausführt (E-063) |
| `app/Livewire/TaskLog.php` | Status und Log einer Aufgabe, aktualisiert jede Sekunde (E-049) |
| `app/Console/Commands/CreateSshKey.php` | `php artisan owpanel:ssh-key` |
| `app/Console/Commands/AddServer.php` | `php artisan owpanel:add-server` |

## Blade-Komponenten

Seiten verwenden diese Komponenten statt kopierter Tabler-Blöcke ([docs/DESIGN.md](../docs/DESIGN.md)). Eine Blade-Komponente ist eine Vorlage unter `resources/views/components/`, die man wie ein HTML-Element einsetzt.

| Komponente | Beispiel |
|---|---|
| Layout | `<x-layouts.app :title="__('dashboard.title')">…</x-layouts.app>` |
| Seitenkopf | `<x-page-header :title="…" :pretitle="…"><x-slot:actions>…</x-slot:actions></x-page-header>` |
| Karte | `<x-card :title="…">…</x-card>`, für Tabellen mit `:flush="true"` |
| Status | `<x-status-badge status="running" />` zeigt Farbe, Icon und Text |
| Icon | `<x-icon name="server" />` aus Tabler Icons |
| Zeitpunkt | `<x-time :value="$task->started_at" />` in der Zeitzone der Oberfläche, `–` für leer |
