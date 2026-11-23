---
title: Installation
description: Install OWPanel on a fresh Debian 13 server.
group: Getting started
icon: terminal-2
slug: installation
---

The installation runs entirely in the terminal and takes about ten minutes, depending on the server. There is deliberately no setup wizard in the browser: that way nobody else can take over a freshly installed panel.

## Preparation

1. Create a server with **Debian 13**, in the Hetzner Cloud for example with the image "Debian 13".
2. Set up a DNS record for the panel's domain, for example `panel.example.com`, as an A and, if available, AAAA record pointing to the server's addresses. Use a name of its own, not the server's host name (such as `srv03.example.com`): if the panel ever moves to another server, its address stays the same.
3. If you use a Hetzner Cloud Firewall, open TCP 22, 80 and 443 and UDP 443 there.
4. Sign in to the server as root over SSH.

## Start the installer

The start script `get-owpanel` downloads the release, checks its checksum and signature, unpacks it and starts the installer. It does not install a release without a valid signature of the OWPanel project; unsigned test builds only with a warning. The second line checks the start script itself first:

```sh
curl -fsSL -o get-owpanel https://raw.githubusercontent.com/goettlichit/owpanel/main/get-owpanel
echo "8c7d3149b9e48bf6323f3255338bde6049e8113548420d5b4723889cd9565271  get-owpanel" | sha256sum -c
bash get-owpanel
```

With `--version 0.1.0` you install a given release instead of the newest.

::: info
The public key of the releases has the fingerprint `SHA256:nyG5UqHzb6roksup2dgAXJoWO57px1eDWM2zLWh+z4E`. GitHub hands out test builds for development (`bash get-owpanel --dev`) only with a fine-grained token with "Actions: Read"; the script asks for it.
:::

If you downloaded the archive yourself, check and start it like this (`ssh-keygen` from OpenSSH checks the signature against the public key of the project):

```sh
curl -fsSL -O https://raw.githubusercontent.com/goettlichit/owpanel/main/release-signers
sha256sum -c owpanel-<version>.tar.gz.sha256
ssh-keygen -Y verify -f release-signers -I owpanel-release -n owpanel-release \
  -s owpanel-<version>.tar.gz.sig < owpanel-<version>.tar.gz
tar -xzf owpanel-<version>.tar.gz
cd owpanel-<version>
./install
```

## What the installer asks

1. **Set up new or restore?** For a new installation choose "new". How to bring back a lost panel from its backup is described under [Recovery](docs:restore).
2. **Domain of the panel**, for example `panel.example.com`. The installer checks that its DNS record points to this server.
3. **Server name:** The installer shows the current host name and asks whether to keep it. Otherwise you enter a new one, short like `srv03` or full like `srv03.example.com`. The installer sets it in `/etc/hostname` and `/etc/hosts` and makes sure cloud-init does not set it back at the next boot. Set the reverse DNS (PTR) of the IP addresses to it at your provider yourself. It is asked only on the first installation; unattended runs change it with `--hostname`.
4. **Time zone**, for example `Europe/Berlin`.
5. **First administrator:** first name, last name, e-mail address and password.
6. **Summary:** The installer shows all answers once more and only starts after you confirm. Until then nothing on the server has changed.

## What the installer does

- It brings the system up to date and pauses the automatic updates meanwhile.
- It sets up NGINX with HTTP/3, PHP-FPM with PHP 8.5 and 8.6, MariaDB, Certbot, nftables and fail2ban. PHP 8.6 is only added once it is available as a final release.
- It creates the users `owpanel-agent` (for control over SSH) and `owpanel-worker` (for background tasks).
- It stores the public key of the releases under `/etc/owpanel/release-signers`. **Server → State** shows its fingerprint later.
- It installs the panel, requests a certificate for its domain and adds the server itself as the first server.
- On a later run, for example for an update, it writes the configuration of all websites again, so new templates reach existing websites too. This runs as tasks you see under **Server → Tasks**.
- At the end it installs all updates once more and offers a restart if an update needs one, such as a new kernel.

Neither Composer nor Node.js run on the server: the release brings everything ready-built.

## Options

You pass all options to `get-owpanel`; what it does not need itself, it hands on to the installer.

| Option | Meaning |
|---|---|
| `--version <version>` | Install a given version instead of the newest, for example `--version 1.0.0`. |
| `--dev` | Install the newest test build from GitHub Actions (needs a token, see above). Test builds stay available for 7 days. |
| `--branch <name>` | With `--dev`: the test build of a given branch instead of `main`, for example to test a pull request. |
| `--domain <domain>` | Domain of the panel; with it the installer asks no questions about domain and host name. |
| `--hostname <name>` | Set the host name of the server. |
| `--timezone <zone>` | Time zone, for example `Europe/Berlin`. |
| `--admin-email`, `--admin-first-name`, `--admin-last-name` | Details of the first administrator. |
| `--admin-password-file <file>` | Read the first administrator's password from a file instead of asking. |
| `--skip-dns-check` | Do not check that the domain points to the server, for example while the DNS record has not arrived everywhere yet. |
| `--reboot`, `--no-reboot` | At the end restart without asking, or do not restart, if an update needs it. |

## After the installation

Open `https://<domain of the panel>` in your browser and sign in with the first administrator. Then continue with the [first steps](docs:first-steps).
