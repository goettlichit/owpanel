---
title: First steps
description: Sign in, set up two-factor and get to know the panel.
group: Getting started
icon: rocket
slug: first-steps
---

## Sign in and set up two-factor

At the first sign-in the panel asks you to set up two-factor authentication. You need an app such as Aegis, 2FAS, Google Authenticator or a password manager with TOTP.

1. Scan the QR code with the app or enter the key by hand.
2. Confirm with the six-digit code from the app.
3. Keep the **recovery codes** in a safe place. With them you get into the panel if your phone is lost.

Under **My account** you later change your password in the **Profile**, two-factor and the [sign-in with Pocket ID](docs:pocket-id) under **Sign-in and security**, and your messages under **Notifications**.

## How the interface is organised

- **At the top** are the areas: Overview, Websites, Customers, Servers and System. To the right you switch language and appearance (system, light, dark).
- **On the left** is the menu of the open object, for a server for example State, Load, Updates, Firewall, SSH, DNS, Snapshots, Backups, Tasks and Logs.
- **At the bottom** are version, licence and the link to the source code.

Cards show their state with a coloured line on top: **blue** means not set up or off, **green** in order, **orange** in progress or attention, **red** failed.

## Tasks

Everything that changes the server runs as a **task** in the background: creating a website, requesting a certificate, changing the firewall, starting a backup. You stay on the page: the button shows a spinner with what is happening, and when the task is done the page reloads and briefly says "done" or "failed". Every task has a status (Pending, Running, Succeeded, Failed) and a log. You find tasks under "Tasks" at the server or website they belong to; after a failure the "Details" link leads straight there.

## Signing out

After signing in, the panel does not ask for your password again. After 60 minutes without activity it signs you out; sign out when you leave the computer.

## Suggested order

1. Set up outgoing mail under **System → Outgoing mail**, so notifications arrive.
2. Choose under **My account → Notifications** which messages you get by e-mail and push.
3. Enter the Hetzner Cloud API token under **System → External services** and set up [snapshots](docs:snapshots) and [backups](docs:backups).
4. Optionally enter the [outside monitoring](docs:notifications#outside-monitoring) under **System → External services**, so you learn when the panel itself fails.
5. Create your first [website](docs:websites).
