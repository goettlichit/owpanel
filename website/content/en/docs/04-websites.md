---
title: Websites
description: Create websites, certificates, SFTP, cron jobs and logs.
group: Operation
icon: world-www
slug: websites
---

## Create a website

Under **Websites → New website** you enter the domain, for example `shop.example.com`, and choose whether to request a certificate (default: yes). Domains with umlauts or other special characters are allowed; the panel converts them itself.

The panel then sets up in the background:

- its own **Linux user** with a random number, such as `ow38217`
- its own **PHP-FPM pool** in the chosen PHP version, PHP 8.5 by default
- the **NGINX configuration**
- a **certificate** from Let's Encrypt with **HTTP/3** over QUIC, HTTP/2 and HTTP/1.1 as fallback

::: info
For the certificate to work, the domain's DNS record must already point to the server. If the domain is at Hetzner, the panel creates the records itself with **Create DNS records at Hetzner**; see [DNS](docs:dns). If the request fails, the website stays reachable over HTTP, and you request the certificate again later with **Request certificate**.
:::

## PHP version

Every website runs on PHP 8.5 or PHP 8.6. OWPanel does not offer older versions on purpose. New websites get PHP 8.5; you can choose another version when creating one.

You switch the version in the website's menu under **PHP** in the card **PHP version**; customers find the same menu entry for their website. The website cannot be reached for about a second while switching. Cron jobs then use the new version too when they call `php`. Check after switching that the website works as usual.

The panel only offers versions installed on the server; you see them under **Server → State**. The installer installs a new version such as PHP 8.6 only once it is available as a final release, not just as a pre-release. If it is not yet, simply run the installer again later.

## PHP settings

Also under **PHP**, the card **PHP settings** sets how much memory a request of the website gets, how large an uploaded file may be at most and how long a request may run at most. It also sets whether errors appear in the browser; only turn that on for debugging. Without a change, 256 MB, 64 MB and 60 seconds apply.

The highest values come from the customer's package, as does the number of PHP processes running for the website at the same time. The card shows what is allowed. Customers change the values under **PHP** for their website; without a package the defaults stay. The values are fixed in the PHP configuration of the website: a `.user.ini` or `ini_set()` cannot raise them. Other settings, such as the time zone, can still be set with a `.user.ini` in the website's folder.

## Compression, browser cache and session cookies

To make websites load faster, NGINX compresses text such as HTML, CSS, JavaScript, JSON and SVG with gzip. Browsers may keep CSS and JavaScript for 7 days, images, fonts, videos and PDF for 30 days. If a file changes often, add a version to the link, such as `style.css?v=2`, and the browser fetches it again at once.

PHP sessions get safe cookies: JavaScript cannot read them (`HttpOnly`), requests from other sites do not carry them (`SameSite=Lax`) and, once the website has a certificate, they only travel over HTTPS (`Secure`). If an application needs other values, such as `SameSite=None` for a return from a payment provider by POST, it sets them itself with `session_set_cookie_params()` or in a `.user.ini`.

## Own rules for the web server

What an application would otherwise have in its `.htaccess` or vhost, you enter as an administrator under **Web server**. Customers do not see this page.

- **Extra headers:** one header per line as `Name: value`, such as `Strict-Transport-Security: max-age=31536000` or a `Content-Security-Policy`. They apply to every response of the website, including images and PDFs, but only with a certificate. OWPanel already sets `X-Frame-Options`, `X-Content-Type-Options` and `Referrer-Policy` itself.
- **Blocked paths:** one path per line. Ending in `/` it blocks a whole folder such as `/vendor/`, otherwise exactly one file such as `/composer.json`. Visitors get "404 Not Found". OWPanel blocks hidden files such as `.env` or `.git` anyway.

There is deliberately no free NGINX text: every line is checked, and the server writes it in a fixed form. Before activating them it checks everything with `nginx -t`; if NGINX refuses the rules, the previous ones stay.

::: tip
Better than blocked paths is a layout where only public files are in `htdocs/public/`, as with Laravel. Blocked paths help with applications that do not provide for that.
:::

## Placeholder page

While the folder `htdocs/public` holds neither an `index.php` nor an `index.html`, the start page shows a simple placeholder page with the domain and a note that a new website is on its way, in German and English. As soon as an index file is uploaded, it disappears by itself; if the customer later deletes everything, it comes back. Other folders without an index file still answer with 403.

For a placeholder page of your own, put it on the server as `/etc/owpanel/site-welcome.html`; the panel replaces `@DOMAIN@` in it with the domain of the website. It applies to new websites at once and to existing ones as soon as their configuration is written again, at the latest on the next run of the installer.

## Also reachable under www

With **Also reachable under www** when creating it, or later with **Turn on www address** under **Website and domain**, the website also answers to `www.<domain>`. Anyone opening that address ends up permanently (301) at the domain, with path and parameters. The option only exists for main domains like `owpanel.org` or `example.co.uk`, where it is preselected. For subdomains like `shop.example.com` it is greyed out.

1. Add an A or AAAA record for `www.<domain>` pointing to the same server in your DNS.
2. Turn on the www address. If the website has a certificate, the panel requests a new one covering both names at once.

If the DNS record is still missing, the domain gets its certificate alone and `www` only redirects over HTTP for now. **Request certificate again** catches up once the record is right. If you turn the www address off, the panel requests a certificate for the domain alone.

## Change the domain

If you no longer like the domain, move the website to a new one under **Websites → website → Website and domain**. Files, databases, SFTP, cron jobs and backups stay as they are, because they belong to the website's user and not to the domain.

1. In DNS, add an A or AAAA record for the new domain pointing to the server; with the www address turned on, also for `www.<new domain>`.
2. Enter the new domain and click **Change domain**.

If the website has a certificate, the panel first requests one for the new domain. Until then the website stays reachable at the old domain. If Let's Encrypt refuses the new domain, for example because the DNS record is still missing, everything stays as it was and you can try again later. Afterwards the old domain is no longer reachable.

## Where the files are

Each website has its own folder that other websites cannot read:

| Path | Contents |
|---|---|
| `htdocs/` | Your files |
| `htdocs/public/` | Document root, what the browser sees |
| `tmp/` | Temporary files of PHP |

The website lives under `/var/www/<user>/`. NGINX writes its access and error logs to `/var/log/nginx/`; in the panel you find them under **Logs**.

The document root `htdocs/public/` fits Laravel and many other frameworks directly. For simple websites just put your files there.

## SFTP

Under **Website → SFTP** you turn access on. The user name is the website's Linux user. You can have a password generated, add SSH keys, or both.

- The access can only do SFTP: no shell, no forwarding.
- It only sees the website's folder and starts in `htdocs`.
- From the command line `scp` (OpenSSH 9 or later), `lftp mirror -R` and `rclone` work, but not `rsync`, since it would need a shell on the server; examples are in the [customer documentation](docs:customer-websites#from-the-command-line). As root on the server `rsync` works of course; set the owner afterwards with `chown -R <user>:<user>`.
- Showing a password is recorded in the audit log.
- As server the panel shows the domain once its DNS points to this server. While it still points elsewhere, for example during a move, it shows the server's IP address, which works at once. The panel checks this when the page is opened; the result is kept for five minutes.
- Administrators who prefer the command line sign in by SSH as root and switch to the website user with `runuser -l <user> -s /bin/bash`. The SFTP page shows the matching command.

## Sending e-mail

OWPanel has no mail server and does not pass on mail from websites. The PHP function `mail()` therefore fails. Websites send mail directly over SMTP to a mail server, with their own mailbox and password. How this works with WordPress, frameworks and other applications is described in the customer documentation under [Sending e-mail](docs:customer-websites#sending-e-mail); you can send your customers there too.

Why OWPanel does not relay mail: all websites would have to send through one shared account. A hacked website could then send spam through that account and get it onto blocklists, together with the panel's notifications. With credentials per website, misuse only affects that website's mailbox.

## Cron jobs

Under **Website → Cron jobs** you add jobs that run regularly, such as `php artisan schedule:run` every minute. The schedule has five fields as in crontab: minute, hour, day, month, weekday. Jobs run as the website's user in the `htdocs` folder. Their output is under **Logs → Cron**.

## Logs

Under **Website → Logs** you see the website's requests, errors and PHP messages, under **Server → Logs** those of the server: sign-ins, fail2ban, NGINX and the system journal.

## Delete a website

**Delete website**, the last entry of the website's menu, leads to a confirmation page. Files, logs, databases, certificate, PHP-FPM pool, NGINX configuration and the Linux user are removed for good. To confirm, you type the domain. If the panel created DNS records for the website, the page lists them; with **Also delete the DNS records the panel created** it removes those that still point exactly to this server. Changed and other records stay. The panel does not delete backups on the Storage Box, but they are no longer reachable in the panel afterwards. If you still need the data, export the databases and download the files over SFTP first.
