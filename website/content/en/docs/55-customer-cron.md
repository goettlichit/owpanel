---
title: Cron jobs and logs
description: Set up recurring tasks and find errors of the website.
audience: customer
icon: clock-play
slug: customer-cron
---

## Cron jobs

Cron jobs are commands that run regularly, such as an application's scheduler. Under **Cron jobs** you create one, change it, pause it or delete it.

The schedule has five fields as in crontab: minute, hour, day, month and weekday. There are presets for the most common cases. Some examples:

| Schedule | Meaning |
|---|---|
| `* * * * *` | every minute |
| `*/15 * * * *` | every 15 minutes |
| `0 3 * * *` | daily at 3 am |
| `0 6 * * 1` | Mondays at 6 am |

Typical commands:

```sh
php artisan schedule:run
php public/wp-cron.php
php scripts/cleanup.php
```

Cron jobs run in the folder `htdocs` and with the PHP version of your website. Your package sets how many cron jobs you have and how often they may run at most.

## Logs

Under **Logs** you see the latest entries of your website, newest line at the bottom:

| Tab | Content |
|---|---|
| Access | every request with address, page and answer |
| Errors | errors of the web server and of PHP |
| PHP | only the PHP messages: errors, warnings, notices |
| Cron | output and errors of your cron jobs |

If your website shows a white page or "500", the reason is usually under **PHP** or **Errors**.
