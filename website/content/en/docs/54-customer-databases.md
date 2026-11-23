---
title: Databases
description: Create, view, export and import MariaDB databases.
audience: customer
icon: database
slug: customer-databases
---

## Creating a database

Under **Databases → New database** you enter a short name, such as `shop`. The panel puts an identifier in front, for example `ow38217_shop`, and creates a user with the same name and a random password. Your package sets how many databases you can have.

The credentials for your application are shown with the database:

| Field | Value |
|---|---|
| Host | `localhost` or `127.0.0.1` |
| Port | `3306` |
| Database and user | the full name, such as `ow38217_shop` |
| Character set | `utf8mb4` |

**Show** shows the password, **New password** sets a new one. Enter it in your application afterwards. Connections are only possible from your website, not from your computer.

## Viewing content

The database tool shows the **tables** with rows and size, the **content** of a table page by page, and runs your own queries under **SQL command**.

## Export and import

- **Export** creates a compressed SQL file with all tables and data. After a short while you download it.
- **Import** loads a `.sql` or `.sql.gz` file, such as an export from your previous provider or from phpMyAdmin, up to 256 MB. If your file is larger, compress it with gzip or ask your provider. The import stops at the first error.

::: warning
An import can overwrite existing tables. Back up first under **Backups** with **Back up now**.
:::

## Deleting a database

**Delete** removes the database and its user for good. Export it first if you still need the data.
