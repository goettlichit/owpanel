---
title: Servers
description: State, load, updates, restart, firewall, SSH, DNS and fail2ban.
group: Operation
icon: server-2
slug: servers
---

## State and load

The panel checks every server once a minute. Under **Server → State** you see operating system, kernel, uptime, the version of the server scripts, the fingerprint of the release key (with a green check when it matches the panel), processor, memory and disk, under **Load** their history. **Services** shows whether nginx, PHP-FPM, MariaDB, fail2ban and nftables run, as far as they are enabled on the server; if one stops, a [notification](docs:notifications) follows.

The server scripts always have the panel's version. If a server differs, for example because it was offline during the panel's update, the panel installs the matching scripts by itself, at most every 15 minutes, and checks their signature. Until then only backups, maintenance and reading tasks run on this server; other tasks fail with a hint. If it takes longer than half an hour, a notification follows.

If the server has more than one network card, for example a Hetzner Cloud private network as well, the load page shows the traffic of each card separately: "Public network" is the card with the default route to the internet, "Private network" every other one. There is nothing to set up.

## Updates

`unattended-upgrades` installs updates every night by itself: security updates from Debian and all updates from nginx.org and packages.sury.org (PHP). Under **Server → Updates** you see:

- whether automatic updates are active and when they last ran
- which updates are still pending, with security updates highlighted
- which packages were installed last, automatically or by hand

The panel checks this every hour. **Check now** first refreshes the package lists of all sources and then reads the list again. **Install all** installs exactly this list (`apt-get dist-upgrade`), including the regular Debian updates that do not come automatically at night. Changed configuration files stay as they are; a restart may be needed afterwards. The panel deliberately does not move to a new Debian release.

On the server the panel runs on, the card **OWPanel** shows the installed and the newest published version; the panel asks GitHub once a day. When a newer version is out, **Changes** leads to its release notes. With the box "I have read the changes" and **Update panel** you install it: the server downloads the release, checks checksum and signature, waits up to ten minutes for running tasks and then starts the installer. The panel is briefly unavailable meanwhile; the page reloads once the new version runs. Data and settings are kept. If something fails, the previous version keeps running, and **Last update** shows why.

With **Install bug fixes automatically** the panel updates itself at night at 4:47, but only to patch versions (such as 1.2.3 to 1.2.4) and a day after their release at the earliest. If tasks are running, the panel tries again the following night. Larger versions you always install by hand; a test build is never updated automatically. By hand on the server, `get-owpanel` still works too.

## Restart

If an update needs a restart, the panel shows a hint on the overview, in the server list and at the server. It never restarts by itself. Under **Server → Updates → Restart server** you type the server name to confirm; the server restarts 15 seconds later.

## Firewall

The firewall uses nftables. Open are TCP 22 (SSH), 80 and 443 and UDP 443 for HTTP/3. Under **Server → Firewall** you open further ports, optionally only for certain addresses or networks such as `203.0.113.0/24`.

::: tip
Every firewall change is rolled back automatically if the panel can no longer reach the server over SSH afterwards. That way you cannot lock yourself out.
:::

### Blocklists

Under **Server → Firewall → Blocklists** you add addresses of public blocklists, for example from ipv64.net, blocklist.de, FireHOL or Spamhaus DROP. Addresses on these lists then no longer reach the server on any port.

- The panel downloads the list at once and checks its format: an address or network at the start of each line, comments with `#`, `;` or `//`, or JSON lines with `cidr`. If it is not a blocklist, the panel says what is wrong.
- You can give each list a name of your own, such as "Spamhaus IPv6"; without one the panel shows the address. Name and update interval can be changed later in the table.
- You choose per list how often the panel fetches it again: every hour, every 6 hours or once a day. **Update now** fetches it at once. If a list cannot be reached once, the last good version stays in use.
- The panel does not take private, reserved or very large networks. Your addresses under **Never ban** (fail2ban) and the addresses of the panel always stay reachable.
- If your own address is on a list, the panel does not accept it until you add the address under **Never ban**.
- At most 10 lists and 250,000 entries together per server.
- Below the table you see which addresses were refused most often, when last and from which of your lists. The table shows for each list how many hits it brought, so you see which list is worth it. Counting starts when it is set up; an address without a hit for 7 days drops out, and after a restart of the server counting starts again.
- If one of your own addresses is on a list, the card warns you: the public addresses of your servers, your current address and the addresses under **Never ban**. It also says whether the address is protected as an exception. If a server address is on a public list, mainly others block you.
- Under **Check address** you enter any IPv4 or IPv6 address and see on which of your lists it is, for example when a customer reports that they cannot get through. The address is not stored.

## SSH

Under server → **SSH** you see whether administrators can log in over SSH with a password and, for root and every other account with login, whether an SSH key is present. **Turn off password login** then allows keys only; SFTP customers keep their password. The panel only turns it off when every one of these accounts has a key, so you do not lock yourself out. **Allow again** takes the setting back.

If sshd uses `AllowUsers` or `AllowGroups` without allowing the group `owpanel-sftp`, SFTP customers cannot get in. The page says so, as does the SFTP page of a website.

## DNS

Under server → **DNS** you see whether the host name of the server (such as `srv03.example.com`) points to its addresses with A and AAAA, as on the DNS page of a website. If the zone is at Hetzner, **Set records** sets missing or wrong records.

Below is the **reverse DNS (PTR)** of each address: which name belongs to the IP and whether that name points back to the IP. For Hetzner servers you enter the name right there, prefilled with the host name; an empty field resets the entry to the Hetzner default. For other providers the panel only shows the entry. Fitting reverse DNS matters above all for sending mail.

## fail2ban

fail2ban bans addresses that repeatedly fail to sign in over SSH: for one hour after 5 attempts within 10 minutes. It also bans scanners looking for hidden files such as `.env` or `.git` on your websites: for 24 hours after 5 such requests within 10 minutes, and only for the websites, SSH stays reachable. NGINX never serves such files anyway; the ban only keeps the scanner from trying further. Under **Server → Firewall** you see the banned addresses, can unban them and add your own addresses under **Never ban**.

## Time zone

The installer sets the server's time zone. The panel shows all times in this time zone and keeps the clock in sync over NTP.

## More servers

From version 0.5.0 the panel adds more servers as website servers, without reinstalling and without migration. The panel stays the gateway: every domain points to the panel, which takes care of certificates and HTTP/3 and passes each request over the private network to the server of the website. The other servers are reachable from outside only for SSH and SFTP.

Requirements:

- The panel server is attached to a private network of the Hetzner Cloud (Cloud Console → Networks). After the next status check the panel knows its private address.
- The new server is a fresh Debian 13 server in the same private network.

How it works:

1. Under **Servers → Add server** enter a name such as `web02` and choose **Create code**. The panel shows the code only now; it works once and for 15 minutes.
2. On the new server, as root, load `get-owpanel` and compare the checksum as for the installation.
3. Run the command shown, e.g. `bash get-owpanel --join panel.example.com --via 10.0.0.2`, and enter the code when asked.
4. The installer asks for the host name, sets up the server and registers it with the panel. This takes a few minutes. The server then appears under **Servers** and can be chosen when you create a website.

If the installer stops with “the panel did not confirm the firewall”, the panel did not reach the server by SSH over the private network; the firewall was rolled back, and after fixing the cause you can try again with a new code.

::: tip
Create all servers in the same network zone (at Hetzner for example Nuremberg, Falkenstein and Helsinki) and put them into a shared **placement group** of the type “spread”. They then run on different physical hosts, and the failure of one host hits only one server. The group costs nothing; existing servers can only join while switched off. Names by role help too, such as `gw01` for the panel server and `web01`, `web02` for more servers.
:::
