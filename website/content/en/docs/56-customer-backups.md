---
title: Backups
description: Back up your website and restore it in whole or in parts.
audience: customer
icon: database-export
slug: customer-backups
---

Your provider backs up your websites regularly, with all files in `htdocs` and all databases. The backups are stored encrypted on another system.

## Viewing backups

Under **Backups** you see all backups of your website. The colour shows the kind: **automatic** blue, **by hand** green, **before restore** orange.

## Back up now

**Back up now** creates a backup at once, for example before an update of your application. Manual backups are kept until your provider deletes them.

## Restoring

Open a backup to look at its content folder by folder. Then choose what comes back:

| Choice | What happens |
|---|---|
| Everything | files and all databases go back to the state of the backup |
| Files only | `htdocs` goes back to the state of the backup; newer files disappear |
| Folder or file | only the chosen item comes back, nothing else changes |
| Databases only | all databases come back, the files stay |
| One database | only the chosen database comes back |

Before every restore the panel creates a backup **before restore**. If you brought back the wrong thing, simply restore that one.

## Limit per day

Your package sets how often a day you can back up and restore yourself; both count together. The page shows how many are left today. Automatic backups do not count.
