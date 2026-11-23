---
title: Databases
description: Create, browse, export and import MariaDB databases.
group: Operation
icon: database
slug: databases
---

## Create a database

Databases always belong to a website. Under **Website → Databases → New database** you enter a name; the panel puts the website's name in front, for example `ow38217_shop`, and creates a user with the same name and a random password. The user only has rights on its own database.

The credentials are shown with the database. Connections are only possible from the server itself:

| Field | Value |
|---|---|
| Host | `localhost` or `127.0.0.1` |
| Port | `3306` |
| Character set | `utf8mb4` |

**Show password** shows the password in plain text; every reveal is recorded in the audit log. Under **New password** you set a new one.

## Database tool

Instead of phpMyAdmin the panel has its own, lean tool:

- **Tables:** name, rows, size and engine of each table
- **Content:** the rows of a table, page by page, with their columns
- **SQL command:** run your own queries, several in a row as well

## Export and import

- **Export** creates a gzip-compressed SQL file with tables, data, views, triggers and routines. It runs as a task; afterwards you download the file.
- **Import** loads a `.sql` or `.sql.gz` file, for example an export from another panel or from phpMyAdmin. The panel removes `DEFINER` clauses of other users. The import stops at the first error.

::: warning
An import can replace existing tables. Make a backup first, for example with **Back up now** under **Website → Backups**.
:::
