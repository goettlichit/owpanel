---
title: Introduction
description: What OWPanel is, who it is for and what you need.
group: Getting started
icon: info-circle
slug: introduction
---

OWPanel (Open Web Panel) is a control panel for Debian servers. It sets up websites, databases, certificates, backups and notifications on your own server, without you editing configuration files. The interface is available in English and German.

::: warning
OWPanel is at version 0.3 and tested on servers in the Hetzner Cloud. Things may still change until version 1.0; read the notes in the [CHANGELOG](https://github.com/goettlichit/owpanel/blob/main/CHANGELOG.md) before an update and keep your backups current.
:::

## Who OWPanel is for

- Agencies and freelancers who run their clients' websites on their own servers.
- Anyone who wants to manage one or more Debian servers with PHP websites simply.
- Anyone who wants to run websites natively: websites run directly on the server, each with its own Linux user.

## Principles

- **Simple:** OWPanel only does what is needed to run websites. Mail servers, DNS servers or a shop for hosting plans are not part of it.
- **Standalone first:** The installation gives a complete panel on one server. More servers join later without migration.
- **Secure by default:** Firewall, fail2ban, separate users per website and two-factor sign-in are active from the start.
- **Everything local:** The panel loads no fonts, scripts or images from other servers and sends no usage data.
- **Traceable:** Every action on the server runs as a task with a log, and the audit log records who did what.

## What you need

| What | For |
|---|---|
| A fresh server with Debian 13 (x86) | Panel and websites run on it. Recommended and tested: a server in the Hetzner Cloud. At least 2 vCPUs and 4 GB of memory; for the panel with its own websites and more servers later, rather 4 vCPUs and 8 GB. |
| Access as root | For the installation, not needed afterwards. |
| A domain for the panel | For example `panel.example.com`; its DNS record must already point to the server. |
| Open ports 80 and 443 (TCP) and 443 (UDP) | For certificates, HTTPS and HTTP/3. Open them in a Hetzner Cloud Firewall, if you use one. |
| Optional: a Hetzner Cloud API token | For snapshots of the whole server and reverse DNS. |
| Optional: a DNS zone at Hetzner | So the panel sets DNS records for websites and servers itself. |
| Optional: a Hetzner Storage Box | For backups of the websites and the panel. |

On other providers OWPanel should run with Debian 13, just without snapshots and backups. This is not tested yet.

## How OWPanel works

The panel is a Laravel application that runs on the server itself. It never changes the server directly, but only starts fixed, checked scripts under `/opt/owpanel/` over SSH. Long actions such as creating a website run in the background; you see their status and log live.

More on this under [Security](docs:security).

## License

OWPanel is free software under the GNU Affero General Public License, version 3 or later (AGPL-3.0-or-later). Anyone may test, use and change it free of charge and without restrictions. If you offer a changed version to others over a network, you must give them the source code of that version.

Report bugs and wishes as an issue in the repository on GitHub. Please report vulnerabilities confidentially, see [Security](docs:security#reporting-vulnerabilities).
