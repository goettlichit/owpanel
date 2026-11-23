---
title: Security
description: How OWPanel protects servers, websites and access.
group: More
icon: shield-lock
slug: security
---

In OWPanel security comes before convenience. These rules apply from the first installation on.

## Sign-in

- Administrators sign in with a password **and** two-factor. Without a second factor set up, there is no way forward.
- Optionally they sign in with a passkey through your own [Pocket ID instance](docs:pocket-id). The panel checks every answer strictly (PKCE, signature, issuer, one-time values) and never matches accounts by e-mail address. Password and two-factor always stay as emergency access.
- After 60 minutes without activity the session ends.
- Sign-in attempts and all forms that change something are limited per minute. If the panel locks a sign-in after too many failed attempts, a [notification](docs:notifications) follows.
- If an administrator signs in from a new device, a notification with browser and address follows as well.
- The audit log records sign-ins and all important actions. The eye at the end of a row shows all details of an entry, such as old and new value, browser and the affected object.

## In the browser

- The panel is only reachable over HTTPS and tells the browser via HSTS to open it only encrypted for one year.
- A Content Security Policy only allows the panel's own scripts. The browser does not run injected scripts.
- The panel cannot be embedded in other sites.

## Control of the server

- The panel never changes the server directly. It connects over SSH as the user `owpanel-agent` and may only run fixed scripts under `/opt/owpanel/bin/` through `sudo`.
- Every script checks its input itself and can be repeated safely.
- The panel stores the SSH host key of every server and checks it on every connection.
- Releases are signed. `get-owpanel` only installs a release with a valid signature of the project; **Server → State** shows whether the stored key matches the panel's.
- Only the background worker `owpanel-worker` can read the panel's SSH key, not the web interface.
- Generated configurations are checked before they are activated. On errors the previous version stays active.

## Websites

- Each website has its own Linux user with a random number and its own PHP-FPM pool.
- Folders of other websites are not readable.
- Database users only have rights on their own database and connect only from the server itself.
- SFTP accounts have no shell and only see the folder of their website.

## Network

- The nftables firewall only allows TCP 22, 80 and 443 and UDP 443, plus what you open yourself.
- Firewall changes are rolled back automatically if the panel cannot reach the server afterwards.
- fail2ban bans addresses after repeated failed sign-ins and scanners looking for hidden files such as `.env` or `.git`. NGINX serves these files on no website.
- Optionally, [blocklists](docs:servers#blocklists) refuse known attackers on all ports. Your exceptions and the addresses of the panel always stay reachable.
- Under Server → SSH you turn off password sign-in over SSH as soon as root and every account with a login has a key ([Servers](docs:servers#ssh)).

## Data

- The panel stores passwords, tokens and keys encrypted.
- Backups are encrypted with restic; each server has its own repository and sub-account.
- The panel loads no fonts, scripts or images from other servers and sends no usage data.

## Reporting vulnerabilities

If you found a security vulnerability, please do not report it publicly but confidentially to goettlichIT. How to do that is described in the file `SECURITY.md` in the source code.
