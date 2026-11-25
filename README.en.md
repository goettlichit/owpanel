# OWPanel by goettlichIT

[Deutsch](README.md) | **English**

**OWPanel** stands for **Open Web Panel**. It is a simple, bilingual server control panel for Debian, as easy to use as CloudPanel. Start with a single server and add more when you need them.

> **Status:** Version 0.2. It runs as a single server (standalone) with websites, databases, customers and a customer area, packages, backups and restore, notifications, a firewall with blocklists, Pocket ID and DNS through the Hetzner API, and updates itself with one click or at night. Things may still change until version 1.0; next comes running several servers (see [docs/ROADMAP.md](docs/ROADMAP.md), German).

## Core idea

- **Simple:** Only the features needed to run websites.
- **Hetzner Cloud first:** Developed and tested on servers in the Hetzner Cloud. Snapshots and backups use the Hetzner API and the Hetzner Storage Box (E-105). Other providers with Debian 13 should work for the basics but are not tested; more backup targets such as S3 may follow.
- **Backups and notifications:** Daily or hourly backups with restic, Hetzner snapshots, restore of single websites and of the whole panel, and alerts by email and push to your phone.
- **Standalone first:** Initial setup gives you a complete panel on one server. Add more servers later without reinstalling or migrating; the panel then takes every request as the gateway and passes it on to the server of the website (planned after the first release, E-161).
- **German and English:** Both languages are equal from day one.
- **Native:** Sites run directly on Debian, each under its own Linux user.
- **Fast:** HTTP/3 over QUIC is enabled for every site with a certificate, with HTTP/2 and HTTP/1.1 as fallback.
- **Secure by default:** An nftables firewall allows only the required ports and rolls back faulty changes automatically.
- **Customer area:** Customers manage their own sites. Billing stays in your own system; an API to connect your own homepage is planned (E-165).
- **Your choice of sign-in:** Use a password with two-factor authentication, or a passkey through your own Pocket ID instance.
- **DNS through the Hetzner API:** The panel checks the DNS records of every website and creates, corrects and removes them in a Hetzner zone. It does not run a DNS server of its own.
- **Developed in the open:** The project is intended for publication on GitHub and for use by others.

## Planned stack

Laravel, Blade, Livewire, Tabler (Bootstrap 5), the Inter typeface, MariaDB, SSH with fixed Bash scripts, NGINX with HTTP/3 (QUIC), PHP-FPM with PHP 8.5 and 8.6 per website, nftables, and Debian 13, with a database tool built into the panel. External systems such as your own homepage will connect through a REST API (planned, E-165).

## Documentation

The project documents in [docs/](docs/) are currently written in German. English versions will follow before the first public release.

- [Contributing](CONTRIBUTING.md)
- [Reporting security issues](SECURITY.md)
- [Changelog](CHANGELOG.md)

## Installation

> Version 0.x: things may still change until version 1.0. Read the notes in the [CHANGELOG](CHANGELOG.md) before an update.

You need a fresh Debian 13 server, root access and a domain for the panel whose DNS entry already points to the server. Ports 80 and 443 must be reachable (with an external firewall also UDP 443).

Recommended and tested is a server in the Hetzner Cloud. Snapshots need an API token of the Hetzner Cloud project, backups a Hetzner Storage Box in the same account. On other providers the panel should run without these two features; this is not tested yet (E-105).

On a first installation the installer brings the system up to date itself and offers a restart at the end if an update needs one. It can also restore a lost panel from its backup, see [docs/WIEDERHERSTELLUNG.md](docs/WIEDERHERSTELLUNG.md) (German).

The start script `get-owpanel` downloads the newest release from GitHub, checks its checksum and signature, unpacks it and starts the installer. The second line checks the start script itself:

```sh
curl -fsSL -o get-owpanel https://raw.githubusercontent.com/goettlichit/owpanel/main/get-owpanel
echo "8c7d3149b9e48bf6323f3255338bde6049e8113548420d5b4723889cd9565271  get-owpanel" | sha256sum -c
bash get-owpanel                 # newest release, --version 0.2.0 for a given one
```

If you downloaded the archive from the [releases page](https://github.com/goettlichit/owpanel/releases) yourself, check and install it like this:

```sh
curl -fsSL -O https://raw.githubusercontent.com/goettlichit/owpanel/main/release-signers
sha256sum -c owpanel-<version>.tar.gz.sha256
ssh-keygen -Y verify -f release-signers -I owpanel-release -n owpanel-release \
  -s owpanel-<version>.tar.gz.sig < owpanel-<version>.tar.gz
tar -xzf owpanel-<version>.tar.gz
cd owpanel-<version>
./install
```

The release key has the fingerprint `SHA256:nyG5UqHzb6roksup2dgAXJoWO57px1eDWM2zLWh+z4E`.

The installer asks for the domain, the host name, the time zone and the first administrator and sets everything up. Then sign in at `https://<domain>` and set up two-factor sign-in. Neither Composer nor Node.js runs on the server.

## License

[GNU Affero General Public License v3.0 or later](LICENSE) (AGPL-3.0-or-later). If you offer a modified version as a network service, you must make its source code available to its users.
