---
title: Getting started
description: Set up your access, sign in and secure your account.
audience: customer
icon: door-enter
slug: customer-start
---

Your provider runs your websites with OWPanel. In the **customer area** you see your websites and manage file access, PHP, databases, cron jobs and backups yourself. Creating or deleting websites and moving them to another domain is up to your provider.

## Setting up your access

You get your access in one of two ways:

- **Invitation link by e-mail:** open the link and choose your password. The link is valid for 72 hours and only once. If it has expired, ask your provider for a new one.
- **Start password:** your provider passes it on to you. After the first sign-in you change it; nothing else works before that.

Your user name is your e-mail address.

## My account

Top right, under your name, you find **My account**:

- **Profile:** your name. Your provider changes the e-mail address.
- **Sign-in and security:** change your password and set up **two-factor authentication**. You need an app such as Aegis, 2FAS, Google Authenticator or your password manager. We strongly recommend it: without the second factor, a guessed or stolen password is enough to reach your websites. Keep the recovery codes in a safe place.
- **Passkey:** if your provider has enabled sign-in with Pocket ID for you, link your account there and then also sign in with a passkey. Password and two-factor remain as a second way.

After 60 minutes without activity the panel signs you out. Switch language and appearance (light, dark or like the system) in the top bar.

## My websites

After signing in you see **My websites** with all websites your provider assigned to you. A click on a website opens its own menu:

| Menu item | What you do there |
|---|---|
| Overview | request a certificate, turn the www address on and off |
| DNS | check whether the domain points to the server |
| PHP | PHP version and PHP settings |
| SFTP | turn file access on and off, password and keys |
| Databases | create, view, export and import |
| Cron jobs | recurring tasks |
| Backups | back up and restore |
| Logs | requests, errors, PHP messages and output of cron jobs |

## Package and storage

Your package sets how much storage, how many databases and cron jobs you have, how often cron jobs may run at most, how often a day you can back up or restore yourself and which PHP settings are allowed. When a limit is reached, the matching buttons are disabled with a hint next to them.

The **Storage** bar above the list shows what the files and databases of all your websites use together. It is measured every 15 minutes. From 90 % it turns orange, at 100 % red. When it is full, your websites can no longer save files, such as uploads or caches. Free up space then, for example old backup copies in `htdocs` or databases you no longer need, or ask your provider for more space.

## Suspended website

If a website shows **Suspended**, your provider has turned it off for now. Visitors see a neutral "temporarily unavailable" page. You can look at everything but not change anything. Your files, databases and backups stay. Please contact your provider in this case.
