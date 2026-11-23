---
title: Backups
description: Back up websites and the panel with restic to a Hetzner Storage Box.
group: Backup
icon: database-export
slug: backups
---

OWPanel backs up the files and databases of each website with [restic](https://restic.net) to a Hetzner Storage Box. Backups are encrypted, deduplicated, and can be browsed and restored one by one.

## How it is organised

- Each server gets **its own sub-account** on the Storage Box. The panel creates it itself through the Hetzner API.
- Each server backs up into **its own encrypted repository**. A server cannot reach the backups of another.
- Each website has its own backups with its files under `htdocs` and all its databases.
- The **panel itself** is backed up as well: its database, the `.env`, the installer's settings and its SSH key.

## Set up

1. Create a **Storage Box** in the Hetzner Console, in the same account as the API token under **System → External services**.
2. Open **Server → Backups** and choose the Storage Box as **Backup target**.
3. Set time, interval and retention and click **Save and connect**.

The panel creates the sub-account, adds a key of its own for the server and sets up the repository. The line on top of the card turns green as soon as everything is connected.

The panel reads how full the Storage Box is from Hetzner once an hour and shows it under **System → Backup**. From 90 % a [notification](docs:notifications) follows, so backups do not only fail once there is no room left.

::: tip
You only need the switch **Also reachable from outside the Hetzner network** if the server is not at Hetzner.
:::

## Schedule and retention

| Setting | Meaning |
|---|---|
| Time | When backups start, for example 02:30 |
| Interval | Daily or every 12, 6, 3 or 1 hours |
| Hourly | For how many hours one backup per hour stays (shorter intervals only) |
| Daily, weekly, monthly | For how many days, weeks and months the newest backup of each stays |

For each website you turn the **automatic backup** on or off under **Website → Backups**.

## Back up by hand

- **Back up now** at the top of **Server → Backups** backs up all websites of the server at once and, on the panel's server, the panel too.
- **Back up now** under **Website → Backups** backs up only this website.

Manual backups stay until you delete them. In the lists you recognise the kind by its colour: **automatic** blue, **by hand** green, **before restore** orange.

## Emergency data

Under **System → Backup → Emergency data** you download a text file with everything you need after a total loss: Storage Box, sub-accounts, repository passwords. Keep it away from the server, printed or in your password manager. Without this file the panel cannot be restored from its backup.

::: danger
Whoever has the emergency data can read all backups. Treat the file like a password.
:::
