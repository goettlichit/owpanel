---
title: Notifications
description: Messages by e-mail and push, quiet hours and devices.
group: More
icon: bell-ringing
slug: notifications
---

The panel reaches you by e-mail and as a push message on phone or desktop when something needs your attention. What you get you choose yourself per occasion and channel under **My account → Notifications**.

## Occasions

**Problems**, reported once and with an all-clear as soon as things are fine again:

- Backup or snapshot failed
- Server not reachable (for three minutes)
- Restart needed, for example after a new kernel
- Disk almost full (from 90 %, all-clear below 85 %)
- Certificate of a website or of the panel expiring soon, because automatic renewal has failed for days
- A customer's storage is used up
- Updates waiting for more than a day, so not installed automatically at night; the message ends silently once they are installed, because "Updates installed" covers that
- Automatic updates switched off on a server
- A service is not running: nginx, PHP-FPM, MariaDB, fail2ban or nftables, if it is enabled on the server
- Storage Box of the backups almost full (from 90 %, all-clear below 85 %)
- The heartbeat to the outside monitoring has failed for 15 minutes

**Done:**

- Backup, snapshot or restore finished
- Server restarted
- Updates installed, automatically or by hand
- A new version of OWPanel is out, once per version
- every failed task, such as a certificate or a firewall change

**Security:**

- An administrator signs in from a new device, with browser and address. The panel recognises devices by a cookie of its own; the first sign-in after the update only learns the device you are signing in from. If you delete cookies or browse privately, the next sign-in counts as a new device.
- A sign-in was locked after too many failed attempts, at most once an hour per account and only for accounts that exist
- A [blocklist](docs:servers#blocklists) of the firewall could not be fetched twice in a row; its last good version stays in use, and the all-clear follows with the next successful fetch

## E-mail

You set up sending under **System → Outgoing mail**, with the SMTP server of your mail provider. **Send a test mail to me** shows whether everything works.

## Push

1. Open **My account → Notifications** on the device that should receive messages.
2. Click **Enable this device** and allow notifications in the browser.

::: info
On iPhone and iPad first add the panel via **Share → Add to Home Screen** and open it from there (iOS 16.4 or later). Only then does Safari allow push messages.
:::

A number on the icon of the installed app shows how many alerts are still open, such as an unreachable server or a failed backup. It changes with every push message and when you open the panel, and disappears when everything is fine. On iPhone and iPad you turn it on or off under **Settings → Notifications → OWPanel → Badges**.

Below each device, **Last deliveries** shows the last 20 push messages with time and the answer of the push service: **accepted**, **rejected** with the error code or **not reached**. "Accepted" means the panel handed the message over at that time; if the device shows it later, the device or the push service held it, for example a scheduled summary or low power mode. The same entries appear under **System → Logs** in the log of the query worker.

Messages travel end-to-end encrypted through the browser's push service (Apple, Google, Mozilla or Microsoft). It only sees that a message arrives, not its contents.

## Outside monitoring

If the panel server fails, it can no longer report that itself. For this there is **Outside monitoring** under **System → External services**: every five minutes the panel calls an address of your monitoring service, such as Healthchecks, Uptime Kuma (monitor type "Push") or ipv64. It only calls while the scheduler and both background services of the panel run. If the calls stop, the monitoring service tells you.

1. Create a check with your monitoring service with a five-minute period and about ten minutes of grace.
2. Enter its address under **Heartbeat address** and click **Check and save**. The panel calls it once right away.

Only HTTPS addresses on the public internet are allowed.

## Quiet hours

During quiet hours, for example at night, all messages wait. Afterwards everything that came up arrives in one message per channel.
