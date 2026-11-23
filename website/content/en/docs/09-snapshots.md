---
title: Snapshots
description: Hetzner snapshots of the whole server, on schedule and by hand.
group: Backup
icon: camera
slug: snapshots
---

A snapshot is an image of the whole server in the Hetzner Cloud. It is the quickest way back when a server no longer runs properly after an update or an error. Snapshots complement [backups](docs:backups) but do not replace them: with a snapshot you only get the whole server back, not a single website.

## Requirement: API token

1. In the Hetzner Cloud Console open the server's project and go to **Security → API tokens**.
2. Create a token with **Read & Write**.
3. Enter it in the panel under **System → External services**.

The panel stores the token encrypted and matches servers to Hetzner servers by their IP address.

## Schedule and retention

Under **Server → Snapshots** you turn on daily snapshots, set the time and decide how many to keep:

- the newest per day for the last days, for example 7
- the newest per week for the last weeks, for example 4

The panel deletes older snapshots after each new one. The newest always stays.

::: info
Hetzner charges snapshots by size. Keeping more snapshots costs more.
:::

## Snapshot by hand

With the schedule turned on, **Create snapshot now** creates an extra snapshot, for example before a larger update. The panel never deletes such snapshots by itself. Snapshots you created in the Hetzner Console are left alone as well.

## Restore a snapshot

Restoring a snapshot replaces the whole server and happens in the Hetzner Cloud Console under **Server → Snapshots → Restore**. The panel deliberately offers no button for this, because it would overwrite itself.
