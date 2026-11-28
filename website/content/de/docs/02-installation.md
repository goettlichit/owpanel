---
title: Installation
description: OWPanel auf einem frischen Debian-13-Server installieren.
group: Einstieg
icon: terminal-2
slug: installation
---

Die Installation läuft vollständig im Terminal und dauert je nach Server etwa zehn Minuten. Einen Einrichtungsassistenten im Browser gibt es bewusst nicht: So kann niemand anderes ein frisch installiertes Panel übernehmen.

## Vorbereitung

1. Lege einen Server mit **Debian 13** an, in der Hetzner Cloud zum Beispiel mit dem Image „Debian 13“.
2. Richte einen DNS-Eintrag für die Domain des Panels ein, etwa `panel.example.de`, als A- und, wenn vorhanden, AAAA-Eintrag auf die Adressen des Servers. Nimm dafür einen eigenen Namen und nicht den Hostnamen des Servers (etwa `srv03.example.de`): Zieht das Panel einmal auf einen anderen Server um, bleibt seine Adresse gleich.
3. Nutzt du eine Hetzner Cloud Firewall, gib dort TCP 22, 80 und 443 sowie UDP 443 frei.
4. Melde dich per SSH als root am Server an.

## Installer starten

Das Startskript `get-owpanel` lädt das Release, prüft Prüfsumme und Signatur, packt es aus und startet den Installer. Ein Release ohne gültige Signatur des OWPanel-Projekts installiert es nicht; Testbauten ohne Signatur nur mit Warnung. Die zweite Zeile prüft vorher das Startskript selbst:

```sh
curl -fsSL -o get-owpanel https://raw.githubusercontent.com/goettlichit/owpanel/main/get-owpanel
echo "8c0c7c270c3e2ef1971afd2e7780561753aff5edcc6591341ce0b2244b34a557  get-owpanel" | sha256sum -c
bash get-owpanel
```

Mit `--version 0.5.0` installierst du ein bestimmtes Release statt des neuesten.

::: info
Der öffentliche Schlüssel der Releases hat den Fingerabdruck `SHA256:nyG5UqHzb6roksup2dgAXJoWO57px1eDWM2zLWh+z4E`. Testbauten für die Entwicklung (`bash get-owpanel --dev`) gibt GitHub nur mit einem Fine-grained Token mit „Actions: Read“ heraus; das Skript fragt danach.
:::

Hast du das Archiv selbst geladen, prüfst und startest du es so (die Signatur prüft `ssh-keygen` aus OpenSSH gegen den öffentlichen Schlüssel des Projekts):

```sh
curl -fsSL -O https://raw.githubusercontent.com/goettlichit/owpanel/main/release-signers
sha256sum -c owpanel-<Version>.tar.gz.sha256
ssh-keygen -Y verify -f release-signers -I owpanel-release -n owpanel-release \
  -s owpanel-<Version>.tar.gz.sig < owpanel-<Version>.tar.gz
tar -xzf owpanel-<Version>.tar.gz
cd owpanel-<Version>
./install
```

## Was der Installer fragt

1. **Neu einrichten oder wiederherstellen?** Für eine neue Installation wählst du „neu“. Wie du ein verlorenes Panel aus seiner Sicherung zurückholst, steht unter [Wiederherstellung](docs:restore).
2. **Domain des Panels**, etwa `panel.example.de`. Der Installer prüft, ob der DNS-Eintrag auf diesen Server zeigt.
3. **Name des Servers:** Der Installer zeigt den aktuellen Hostnamen und fragt, ob er bleiben soll. Sonst gibst du einen neuen ein, kurz wie `srv03` oder vollständig wie `srv03.example.de`. Der Installer setzt ihn in `/etc/hostname` und `/etc/hosts` und sorgt dafür, dass cloud-init ihn beim nächsten Neustart nicht zurücksetzt. Den Reverse-DNS-Eintrag (PTR) der IP-Adressen stellst du bei deinem Anbieter selbst passend ein. Gefragt wird nur bei der ersten Installation; ohne Rückfragen änderst du ihn mit `--hostname`.
4. **Zeitzone**, etwa `Europe/Berlin`.
5. **Erster Administrator:** Vorname, Nachname, E-Mail-Adresse und Passwort.
6. **Zusammenfassung:** Der Installer zeigt alle Angaben noch einmal und startet erst nach deiner Bestätigung. Bis dahin ist am Server nichts geändert.

Die Fragen des Installers sind auf Englisch; die Sprache des Panels stellst du später im Browser ein.

## Was der Installer tut

- Er bringt das System auf den neuesten Stand und pausiert dafür die automatischen Updates.
- Er richtet NGINX mit HTTP/3, PHP-FPM mit PHP 8.5 und 8.6, MariaDB, Certbot, nftables und fail2ban ein. PHP 8.6 kommt erst dazu, wenn es als endgültige Version bereitsteht.
- Er legt die Benutzer `owpanel-agent` (für die Steuerung per SSH) und `owpanel-worker` (für Hintergrundaufgaben) an.
- Er hinterlegt den öffentlichen Schlüssel der Releases unter `/etc/owpanel/release-signers`. Seinen Fingerabdruck zeigt später **Server → Zustand**.
- Er installiert das Panel, fordert ein Zertifikat für seine Domain an und trägt den Server selbst als ersten Server ein.
- Bei einem erneuten Lauf, etwa für ein Update, schreibt er die Konfiguration aller Webseiten neu, damit neue Vorlagen auch bei bestehenden Webseiten ankommen. Das läuft als Aufgaben, die du unter **Server → Aufgaben** siehst.
- Am Ende spielt er noch einmal alle Updates ein und bietet einen Neustart an, falls ein Update ihn verlangt, etwa ein neuer Kernel.

Auf dem Server laufen dabei weder Composer noch Node.js: Das Release bringt alles fertig gebaut mit.

## Optionen

Alle Optionen gibst du `get-owpanel` mit; was es nicht selbst braucht, reicht es an den Installer weiter.

| Option | Bedeutung |
|---|---|
| `--version <Version>` | Eine bestimmte Version statt der neuesten installieren, etwa `--version 1.0.0`. |
| `--dev` | Den neuesten Testbau aus GitHub Actions installieren (braucht ein Token, siehe oben). Testbauten bleiben 7 Tage abrufbar. |
| `--branch <Name>` | Mit `--dev`: den Testbau eines bestimmten Branches statt `main`, etwa zum Testen eines Pull Requests. |
| `--domain <Domain>` | Domain des Panels; mit ihr stellt der Installer keine Fragen zu Domain und Hostnamen. |
| `--hostname <Name>` | Hostname des Servers setzen. |
| `--timezone <Zone>` | Zeitzone, etwa `Europe/Berlin`. |
| `--admin-email`, `--admin-first-name`, `--admin-last-name` | Angaben zum ersten Administrator. |
| `--admin-password-file <Datei>` | Passwort des ersten Administrators aus einer Datei lesen statt abfragen. |
| `--skip-dns-check` | Nicht prüfen, ob die Domain auf den Server zeigt, etwa wenn der DNS-Eintrag noch nicht überall angekommen ist. |
| `--reboot`, `--no-reboot` | Am Ende ohne Rückfrage neu starten oder nicht neu starten, falls ein Update es verlangt. |

## Nach der Installation

Öffne `https://<Domain des Panels>` im Browser und melde dich mit dem ersten Administrator an. Danach geht es mit den [ersten Schritten](docs:first-steps) weiter.
