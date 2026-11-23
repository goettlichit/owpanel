---
title: Customers and users
description: Create customers, assign websites and manage administrators.
group: Operation
icon: users
slug: customers
---

## Customers

Under **Customers** you create customers and assign websites to them. The panel only stores what it needs for operation: first name, last name, optionally the company, e-mail address and language. The panel assigns the customer number itself; you can add the number under which your own system knows the customer.

Billing, contracts and further customer records do not belong in the panel but in your own system. It will be connected later through a REST API and webhooks.

## Customer area

What your customers see and do there is described in the documentation [for customers](docs:customer-start). The panel links customers there under **Help** at the bottom of every page. If you have an own help page, enter it under **System → Settings**; the link then leads there.

Once a customer has an access (customer → **Access**), he sees **My websites** after signing in, with the websites assigned to him. Each website gets its own menu:

- **Overview:** domain, request a certificate and turn the www address on or off.
- **DNS:** a check whether the records point to the server, with the target addresses. He cannot change anything there; he does that at his DNS provider.
- **SFTP:** turn access on and off, show or renew the password, add keys.
- **Databases:** create and delete, credentials and password, new password, the database tool with tables and SQL, export and import.
- **Cron jobs:** create, change, pause and delete.
- **Backups:** see backups, **Back up now** and restore whole or in parts, together at most as often per day as his package allows. Only you switch the automatic backup on or off.
- **Logs:** access, error, PHP and cron log of his website.

He sees no servers, Linux paths, task logs or other customers, and the pages of the administration stay closed to him. He creates new databases and cron jobs only within his package, and none without one; a cron job you allowed beyond the limit he can only delete. What a customer changes appears with his name in the audit log.

## Assignment and packages

Every website belongs to a **customer**, to the **administration** or is **unassigned**. You set this when creating it or later under **Website and domain → Assignment**. Websites of the administration are your own, such as your homepage. The list of all websites shows them in three groups divided by a line: administration, unassigned and customers.

Under **Customers → Packages** you set how much storage and how many websites, databases and cron jobs a customer may have and how often his cron jobs may run at most. The section **PHP** holds the highest memory, upload size and run time of each website and the number of PHP processes per website; a new package starts with 512 MB, 128 MB, 300 seconds and 10 processes. The memory of a website is thus at most processes × memory. When you change these values, they apply at once to all websites of the customers with the package; settings above them are cut. The limits apply only to websites assigned to a customer with a package and count over all his websites. Websites of the administration and unassigned websites have no limits; for unassigned websites and customers without a package the panel shows a note.

If you as an administrator go beyond a limit, such as a cron job every 5 minutes with an hourly package, the panel shows the limit and asks. **Save anyway** goes on; this is recorded in the audit log. Customers cannot go beyond a limit, and a cron job beyond it can only be deleted by them.

## Storage

The storage of a package covers files and databases of all websites of a customer together. It is measured every 15 minutes on every server.

- **Customer:** Under **My websites** he sees a bar with used, limit and free, split into files and databases, and the usage of each website in the list. From 90 % the bar turns orange, from 100 % red, each with a note to free some space or contact you. When the storage is full, new databases and imports are greyed out.
- **You:** Under customer → **Package** you see the same bar and the usage of each website. The list of all websites has the column **Used**, and the overview of each website shows files and databases, also for websites of the administration and unassigned ones, there without a limit. When a customer is full, you get the notification **Customer storage full** and an all-clear as soon as there is room again.

The file system enforces the limit for files with Linux quotas: each website may use its own files plus the free space of the customer. When everything is full, his websites can no longer write files, such as uploads or caches. Databases are only measured. The base setup turns quotas on for ext4 and changes `/etc/fstab` for this, with a check and a rollback on failure. On other file systems the panel only measures and notes that the limit is not enforced. The disk of the whole server stays on the server page.

## Suspending websites

Under website → **Suspension** you suspend a website, for example for an unpaid invoice. An internal note helps you later; the customer does not see it. Optionally you lock the customer's access as well.

- **Visitors** see a neutral "temporarily unavailable" page with HTTP 503, without a reason. Search engines take this as temporary.
- **On the server** PHP, cron jobs and SFTP rest, open SFTP connections end. Files, databases and backups stay as they are, and the certificate keeps renewing.
- **The customer** sees "Suspended" in the customer area and a note to contact you. He can look at everything but change nothing.
- **You** can unsuspend, delete, back up and restore a suspended website and export its databases. Everything else waits until it is unsuspended; the pages of the website show a red note about it.

**Unsuspend** brings the website back exactly as it was. A locked access is unlocked separately under customer → **Access**.

## Administrators

Under **System → Users** you add more administrators. They get a start password that you pass on securely, and set up two-factor authentication at their first sign-in. Administrators may do everything in the panel.

## Audit log

**System → Audit log** shows who did what and when: sign-ins, created and deleted websites, changed firewall rules, shown passwords, started backups and more.
