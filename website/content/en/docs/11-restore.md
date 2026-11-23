---
title: Recovery
description: Restore websites, rebuild missing websites and restore the panel after a total loss.
group: Backup
icon: history
slug: restore
---

## Restore a website

Under **Website → Backups** you see all backups of the website. Open a backup to browse its contents folder by folder, and choose what comes back:

| Scope | What happens |
|---|---|
| Everything | Files and all databases return to the state of the backup |
| Files only | `htdocs` returns to the state of the backup; files that did not exist then disappear |
| Folder or file | Only the chosen folder or file comes back, nothing else changes |
| Databases only | All databases of the website come back, the files stay |
| One database | Only the chosen database comes back |

Before every restore the panel makes a **safety backup**, marked **before restore** in the list. If you restored the wrong thing, simply restore this backup. The last five of them are kept.

## Rebuild missing websites

If the panel knows a website that no longer exists on the server, for example after the panel was restored on a new server, **Server → Backups** shows the card **Missing websites**. **Rebuild from backups** rebuilds each missing website in these steps:

1. Create the website, without a certificate at first
2. Create its databases with their stored passwords
3. Restore files and databases from the newest backup
4. Set up cron jobs and SFTP access again
5. Request the certificate again

Before that the panel puts back the server's own firewall rules and fail2ban exceptions. Each step is a task of its own with a log. If one fails, the panel skips the remaining steps of that website; the others continue. You rebuild a single website under **Website → Backups**, also from an older backup if you like.

## Restore the panel after a total loss

If the panel's server is lost, you bring the panel back on a new server from its backup. You need the [emergency data](docs:backups#emergency-data) for this.

1. **Create a new server** with Debian 13 and point the domain of the old panel to it.
2. **Reset the sub-account's password:** in the Hetzner Console, at the Storage Box under **Sub-accounts**, open the sub-account of the old panel server and set a new password. The panel does not know the old one.
3. **Start the installer** as described under [Installation](docs:installation) and choose **restore** at the first question. The installer asks for Storage Box, sub-account, password and repository password from the emergency data and offers the last ten backups to choose from.
4. **Restore the websites too:** answer yes when it asks whether to rebuild the websites of this server as well. As soon as the backups are connected, the panel rebuilds them as described above.
5. **Sign in** with your old account and your old two-factor app.

If the server needs a restart after the updates, the installer waits until the websites are done and shows the progress every 30 seconds. Press Enter to stop waiting; the rebuild continues in the panel.

::: info
Afterwards **System → Backup** shows which backup the panel came from. You can hide the note with the cross; in the list of panel backups the backup stays marked **restored**.
:::

## What is not backed up

- Logs and the `tmp` folder of a website start empty after a rebuild.
- Certificates are requested again, not restored.

A [snapshot](docs:snapshots) of the old server is often the quicker way if the server itself still exists.
