---
title: Your website
description: Certificate, www address, DNS, folders, SFTP and sending e-mail.
audience: customer
icon: world-www
slug: customer-websites
---

## Certificate and HTTPS

Every website gets a free certificate from Let's Encrypt. With it, the website is reachable over `https://`, also over fast HTTP/3. The certificate renews itself.

For this the domain must point to the server. If the certificate is still missing, first check under **DNS** that all records are right, then click **Request certificate** in the **Overview**.

## Also reachable under www

For main domains such as `example.com` you can turn on the www address in the **Overview**. Whoever opens `www.example.com` then lands on `example.com`.

1. At your DNS provider, create the same records for `www` as for the domain. The **DNS** page shows the addresses.
2. Turn on the www address. The certificate is extended to the www address.

If the DNS record is still missing, `www` works only without HTTPS for now. As soon as the record is right, request the certificate again.

## Checking DNS

You keep managing your domain at your DNS provider. The **DNS** page shows which addresses the A (IPv4) and AAAA (IPv6) records must have ("Target") and what your DNS provider currently answers. You cannot change records there. After a change at your DNS provider it usually takes a few minutes, sometimes a few hours, until all visitors get the new address. **Check DNS** asks again at once.

## Folders of the website

| Folder | Content |
|---|---|
| `htdocs/` | your files |
| `htdocs/public/` | what visitors see in the browser |
| `tmp/` | temporary files of PHP |

For a simple website, put everything into `htdocs/public/`. Applications such as Laravel or Symfony that bring their own `public` folder go into `htdocs/`; then their `public` fits exactly. This keeps configuration and code outside of what visitors can fetch.

As long as `htdocs/public/` has neither `index.php` nor `index.html`, the website shows a placeholder page. It disappears as soon as you upload your start page.

Files whose name starts with a dot, such as `.env` or `.git`, are never served. Whoever searches for them in bulk gets banned.

::: info
The web server is NGINX, not Apache, so a `.htaccess` has no effect. The usual pretty URLs of WordPress, Laravel and similar applications still work: everything that is not a file goes to `index.php`. If you need your own redirects, ask your provider.
:::

## Files over SFTP

Under **SFTP** you turn on file access. It shows server, port and user name. You can have a password generated and shown, add an SSH key, or both. Every time the password is shown, it is logged.

Connect with an SFTP program such as FileZilla, WinSCP or Cyberduck. The access only sees the folder of your website and starts in `htdocs`. There is no command line. Turn the access off while you do not need it.

## Faster and safer, without any effort

- Text such as HTML, CSS and JavaScript is transferred compressed.
- Browsers keep CSS and JavaScript for 7 days, images, fonts and videos for 30 days. If you change a file often, add a version to the link, such as `style.css?v=2`; then the browser fetches it again at once.
- PHP sessions get safe cookies: not readable by JavaScript, not sent with requests from other sites, and with a certificate only over HTTPS. If your application needs other values, for example for the return from a payment provider, it sets them itself.

## Sending e-mail

Your website does not send e-mail through the web server but directly over SMTP to a mail server, with its own mailbox and password. The PHP function `mail()` does not work. This is how to set it up:

- **WordPress:** an SMTP plugin such as *WP Mail SMTP* or *FluentSMTP*.
- **Laravel, Symfony and other frameworks:** the SMTP settings in `.env` (`MAIL_MAILER=smtp`, host, port, user, password).
- **Shop, billing and ticket systems:** almost always an SMTP setting in the application's administration.
- **Own PHP:** a library such as PHPMailer or Symfony Mailer instead of `mail()`.

Use port 587 with STARTTLS; ports 25 and 465 are often blocked. To keep mail out of spam folders, the SPF record of your sender domain must allow the mail server, and the mail server should sign with DKIM. You get the credentials from your mail provider.
