---
title: Moving a website
description: Take over an existing website from another provider.
audience: customer
icon: truck-delivery
slug: customer-move
---

This is how you move a website from your previous provider without visitors noticing. The old website keeps running until everything is done.

## Preparing

1. **Lower the TTL:** one day before, set the TTL of the A and AAAA records at your DNS provider to 300 seconds. Then the later switch takes effect within minutes.
2. **Have the website created:** your provider creates the website with your domain, at first without a certificate, because the domain still points to the old server.
3. **Take stock at the old provider:** note the PHP version, databases, cron jobs and your own redirects, for example from a `.htaccess`.

## Moving files and database

1. Download the files at the old provider and upload them over [SFTP](docs:customer-websites#files-over-sftp) to `htdocs/public/`. If your application brings its own `public` folder, like Laravel, upload it to `htdocs/`.
2. Under [PHP](docs:customer-php), set the matching version and the settings you need, such as the upload size.
3. Export the database at the old provider, create a new one under [Databases](docs:customer-databases) and import the export.
4. Enter the new database credentials in your application's configuration, with host `localhost`. Adjust paths if the application stored fixed paths.
5. Create the [cron jobs](docs:customer-cron) again.
6. Switch sending e-mail to [SMTP](docs:customer-websites#sending-e-mail) if your application uses `mail()` so far.

## Testing first

You can look at the new website before the domain moves. On your computer, add the new address to the `hosts` file. The address is shown under **DNS** as "Target".

- Windows: `C:\Windows\System32\drivers\etc\hosts`, edit as administrator
- macOS and Linux: `/etc/hosts`, edit with `sudo`

```
203.0.113.10  example.com www.example.com
```

Your browser then opens the domain on the new server, everybody else still on the old one. Remove the entry after testing.

## Switching over

1. For shops and other websites with ongoing orders or sign-ups: put the old website into maintenance mode. Then move the database once more and, if files changed, those too.
2. At your DNS provider, change the A and AAAA records to the addresses under **DNS**.
3. As soon as **DNS** shows everything green, click **Request certificate** in the **Overview**.
4. Check the website, forms and sending e-mail.

Leave the old website unchanged for a few days before you cancel it. Then you can quickly go back if there is a problem. Afterwards, set the TTL back to its old value.
