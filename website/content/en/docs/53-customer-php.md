---
title: PHP
description: Switch the PHP version, set memory, upload size and run time.
audience: customer
icon: brand-php
slug: customer-php
---

## PHP version

Your website runs with PHP 8.5 or 8.6. Under **PHP → PHP version** you switch the version. The website is unreachable for about a second. Cron jobs that call `php` then use the new version too.

After switching, check that everything works as usual. If there are errors, simply switch back. Whether your application supports a version is usually stated in its system requirements.

## PHP settings

Under **PHP → PHP settings** you set:

| Setting | Meaning | Without a change |
|---|---|---|
| Memory | how much a single request may use | 256 MB |
| Upload size | how large an uploaded file may be at most | 64 MB |
| Run time | how long a request may run at most | 60 seconds |
| Show errors in the browser | PHP errors appear on the page | off |

The highest values come from your package; the card shows what is allowed. The values apply right after saving.

::: warning
Only turn on **Show errors in the browser** briefly for debugging. Error messages reveal paths and sometimes credentials to visitors. You also find the messages without this switch under **Logs → PHP**.
:::

## More settings with .user.ini

Other PHP settings go into a file `.user.ini` in `htdocs/public/`, for example the time zone:

```ini
date.timezone = Europe/Berlin
```

PHP reads the file again after five minutes at most. Memory, upload size and run time cannot be raised above the values from the panel this way.
