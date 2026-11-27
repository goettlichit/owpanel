# OWPanel by goettlichIT

**Deutsch** | [English](README.en.md)

**OWPanel** steht für **Open Web Panel** und ist ein einfaches, zweisprachiges Server-Control-Panel für Debian: so leicht zu bedienen wie CloudPanel. Du startest mit einem einzelnen Server und erweiterst bei Bedarf auf mehrere.

> **Status:** Version 0.3. Sie läuft als einzelner Server (Standalone) mit Websites, Datenbanken, Kunden und Kundenbereich, Paketen, Backups und Wiederherstellung, Benachrichtigungen, Firewall mit Sperrlisten, Pocket ID und DNS über die Hetzner-API und aktualisiert sich per Klick oder nachts selbst. Bis Version 1.0 kann sich noch einiges ändern; als Nächstes kommt der Betrieb mit mehreren Servern (siehe [docs/ROADMAP.md](docs/ROADMAP.md)).
> Alle Festlegungen sind vorläufig. Änderungen werden in [docs/ENTSCHEIDUNGEN.md](docs/ENTSCHEIDUNGEN.md) festgehalten.

## Kernidee

- **Einfach:** Nur Funktionen, die für den Betrieb von Websites gebraucht werden.
- **Standalone zuerst:** Die Ersteinrichtung ergibt ein vollständiges Panel auf einem Server. Weitere Server lassen sich später ohne Neuinstallation oder Migration hinzufügen; das Panel nimmt dann als Eingangstor alle Anfragen an und reicht sie an den Server der Website weiter (geplant nach dem ersten Release, E-161).
- **Deutsch und Englisch:** Beide Sprachen sind von Anfang an gleichwertig.
- **Nativ:** Websites laufen direkt auf dem Debian-Server, jede mit eigenem Linux-Benutzer.
- **Schnell:** HTTP/3 über QUIC ist für alle Websites mit Zertifikat aktiv, HTTP/2 und HTTP/1.1 bleiben als Rückfall.
- **Hetzner Cloud zuerst:** Entwickelt und getestet wird auf Servern in der Hetzner Cloud. Snapshots und Backups nutzen die Hetzner-API und die Hetzner Storage Box (E-105). Andere Anbieter mit Debian 13 sollten für den Grundbetrieb funktionieren, sind aber nicht getestet; weitere Backup-Ziele wie S3 können folgen.
- **Backups und Benachrichtigungen:** Tägliche oder stündliche Sicherungen mit restic, Hetzner-Snapshots, Wiederherstellung einzelner Websites und des ganzen Panels sowie Meldungen per E-Mail und Push aufs Handy.
- **Sicher voreingestellt:** Eine nftables-Firewall lässt nur die nötigen Ports zu und nimmt fehlerhafte Änderungen automatisch zurück.
- **Kundenbereich:** Kunden verwalten ihre eigenen Websites. Abrechnung bleibt in deinem eigenen System; eine API für die Anbindung der eigenen Homepage ist geplant (E-165).
- **Anmeldung nach Wahl:** Per Passwort mit Zwei-Faktor oder alternativ per Passkey über eine eigene Pocket-ID-Instanz.
- **DNS über die Hetzner-API:** Das Panel prüft die DNS-Einträge jeder Website und legt sie in einer Hetzner-Zone an, korrigiert sie und entfernt sie beim Löschen. Ein eigener DNS-Server gehört nicht dazu.
- **Offen entwickelt:** Das Projekt ist für die Veröffentlichung auf GitHub und die Nutzung durch andere vorgesehen.

## Geplanter Technik-Stack

| Bereich | Technik |
|---|---|
| Panel | PHP, Laravel, Blade |
| Oberfläche | Tabler (Bootstrap 5), Schrift Inter |
| Interaktivität | Livewire, Alpine.js für Kleinigkeiten |
| Hintergrundjobs | Laravel Queues in der Panel-Datenbank |
| Panel-Datenbank | MariaDB |
| Anmeldung | Passwort in der Panel-Datenbank mit Zwei-Faktor oder alternativ Pocket ID per OIDC |
| Serversteuerung | SSH und feste Bash-Skripte |
| Zielsystem | Debian 13 |
| Webserver | NGINX mit HTTP/3 (QUIC), HTTP/2 und HTTP/1.1, dazu PHP-FPM mit PHP 8.5 und 8.6 je Website |
| Firewall | nftables mit TCP 22, 80, 443 und UDP 443 |
| Externe Anbindung | REST-API für die eigene Homepage (geplant, E-165) |
| Datenbankverwaltung für Nutzer | eigenes Werkzeug im Panel (Tabellen, Inhalte, SQL) |

## Dokumentation

| Datei | Inhalt |
|---|---|
| [docs/KONZEPT.md](docs/KONZEPT.md) | Ziel, Zielgruppe, Umfang und Abgrenzung |
| [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md) | Aufbau, Abläufe und Datenmodell |
| [docs/DESIGN.md](docs/DESIGN.md) | Oberfläche, Tabler und Schrift |
| [docs/SICHERHEIT.md](docs/SICHERHEIT.md) | Verbindliche Sicherheitsregeln |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Geplante Meilensteine |
| [docs/ENTSCHEIDUNGEN.md](docs/ENTSCHEIDUNGEN.md) | Entscheidungen und offene Fragen |
| [docs/WIEDERHERSTELLUNG.md](docs/WIEDERHERSTELLUNG.md) | Panel nach einem Totalausfall wiederherstellen |
| [website/](website/README.md) | Projekt-Website mit Dokumentation für Betreiber |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Mitarbeit am Projekt |
| [SECURITY.md](SECURITY.md) | Sicherheitslücken melden |
| [CHANGELOG.md](CHANGELOG.md) | Änderungen je Version |
| [CLAUDE.md](CLAUDE.md) | Arbeitsanweisungen für die Zusammenarbeit mit Claude |

## Geplante Verzeichnisstruktur

```text
owpanel/
├── install             # Ersteinrichtung des Panels (im Release-Archiv ganz oben)
├── panel/              # Laravel-Anwendung
├── scripts/            # Bau des Release-Archivs
├── server/
│   ├── bootstrap/      # Einbindung weiterer Server
│   ├── provision/      # Agent-Benutzer und Grundeinrichtung der Dienste
│   ├── bin/            # Verwaltungsskripte, z. B. site-create
│   ├── lib/            # Gemeinsame Bash-Funktionen
│   └── templates/      # Konfigurationsvorlagen
├── tests/              # Tests für Serverskripte
└── docs/               # Projektdokumentation
```

## Installation

> Version 0.x: Bis Version 1.0 kann sich noch einiges ändern. Lies vor einem Update die Hinweise im [CHANGELOG](CHANGELOG.md).

Voraussetzungen: ein frischer Server mit Debian 13, Zugang als root und eine Domain für das Panel, deren DNS-Eintrag schon auf den Server zeigt. Port 80 und 443 müssen erreichbar sein (bei einer externen Firewall auch UDP 443).

Empfohlen und getestet ist ein Server in der Hetzner Cloud. Für Snapshots braucht das Panel ein API-Token des Hetzner-Cloud-Projekts, für Backups eine Hetzner Storage Box im selben Konto. Auf anderen Anbietern sollte das Panel ohne diese beiden Funktionen laufen; das ist noch nicht getestet (E-105).

Der Installer bringt das System bei einer ersten Installation selbst auf den neuesten Stand und bietet am Ende einen Neustart an, falls ein Update ihn verlangt (E-102).

Das Startskript `get-owpanel` lädt das neueste Release von GitHub, prüft Prüfsumme und Signatur, packt es aus und startet den Installer (E-071, E-167). Die zweite Zeile prüft das Startskript selbst:

```sh
curl -fsSL -o get-owpanel https://raw.githubusercontent.com/goettlichit/owpanel/main/get-owpanel
echo "8c7d3149b9e48bf6323f3255338bde6049e8113548420d5b4723889cd9565271  get-owpanel" | sha256sum -c
bash get-owpanel                 # neuestes Release, mit --version 0.3.0 ein bestimmtes
```

Bei einer ersten Installation fragt der Installer, ob er ein neues Panel einrichten oder ein verlorenes aus seiner Sicherung auf der Storage Box wiederherstellen soll; siehe [docs/WIEDERHERSTELLUNG.md](docs/WIEDERHERSTELLUNG.md).

Wer das Archiv von der [Release-Seite](https://github.com/goettlichit/owpanel/releases) selbst geladen hat, prüft und installiert es so:

```sh
curl -fsSL -O https://raw.githubusercontent.com/goettlichit/owpanel/main/release-signers
sha256sum -c owpanel-<Version>.tar.gz.sha256
ssh-keygen -Y verify -f release-signers -I owpanel-release -n owpanel-release \
  -s owpanel-<Version>.tar.gz.sig < owpanel-<Version>.tar.gz
tar -xzf owpanel-<Version>.tar.gz
cd owpanel-<Version>
./install
```

Der Release-Schlüssel hat den Fingerabdruck `SHA256:nyG5UqHzb6roksup2dgAXJoWO57px1eDWM2zLWh+z4E`.

Testbauten aus GitHub Actions (`bash get-owpanel --dev`, mit `--branch <Name>` von einem anderen Branch) gibt GitHub nur mit einem Token heraus, auch bei öffentlichen Repositorys: ein Fine-grained Token mit „Actions: Read“, nach dem das Skript fragt oder den es aus `GITHUB_TOKEN` liest. Testbauten sind für die Entwicklung gedacht.

Der Installer fragt Domain, Hostname, Zeitzone und den ersten Administrator ab und richtet alles ein. Danach meldest du dich unter `https://<Domain>` an und richtest die Zwei-Faktor-Anmeldung ein. Auf dem Server laufen weder Composer noch Node.js (E-068). Details stehen in [docs/ARCHITEKTUR.md](docs/ARCHITEKTUR.md), Abschnitt „Ablauf: Ersteinrichtung“.

## Lizenz

[GNU Affero General Public License v3.0 oder neuer](LICENSE) (AGPL-3.0-or-later). Wer eine veränderte Fassung als Dienst über ein Netzwerk anbietet, muss den Nutzern den Quellcode dieser Fassung zugänglich machen.

## Nächster Schritt

Version 0.1 war das erste öffentliche Release (Meilenstein 8), Version 0.2 bringt Updates aus dem Panel für Panel und Serverskripte, Version 0.3 die ersten Schritte zu Multi-Server: mehrere Auftrags-Worker mit einer Aufgabe je Server und die Gateway-Adressen. Als Nächstes folgen externe Testnutzer und Multi-Server mit dem Panel als Eingangstor (Meilenstein 5), der Umzug von Websites (5.1) und eigene Dienste für Administratoren (9); die API für die eigene Homepage (7.1) kann vorgezogen werden. Details stehen in der [Roadmap](docs/ROADMAP.md). Wie das Panel lokal startet, steht in [panel/README.md](panel/README.md).
