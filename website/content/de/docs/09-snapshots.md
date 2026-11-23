---
title: Snapshots
description: Hetzner-Snapshots des ganzen Servers nach Zeitplan und von Hand.
group: Sicherung
icon: camera
slug: snapshots
---

Ein Snapshot ist ein Abbild des ganzen Servers in der Hetzner Cloud. Er ist der schnellste Weg zurück, wenn ein Server nach einem Update oder Fehler nicht mehr richtig läuft. Snapshots ergänzen die [Backups](docs:backups), ersetzen sie aber nicht: Mit ihnen holst du nur den ganzen Server zurück, keine einzelne Webseite.

## Voraussetzung: API-Token

1. Öffne in der Hetzner Cloud Console das Projekt des Servers und gehe zu **Sicherheit → API-Tokens**.
2. Erzeuge ein Token mit **Lesen und Schreiben**.
3. Trage es im Panel unter **System → Externe Dienste** ein.

Das Panel speichert das Token verschlüsselt und ordnet Server und Hetzner-Server über ihre IP-Adresse zu.

## Zeitplan und Aufbewahrung

Unter **Server → Snapshots** schaltest du die täglichen Snapshots ein, legst die Uhrzeit fest und bestimmst, wie viele du behältst:

- den neuesten je Tag für die letzten Tage, etwa 7
- den neuesten je Woche für die letzten Wochen, etwa 4

Ältere Snapshots löscht das Panel nach jedem neuen Snapshot. Der neueste bleibt immer.

::: info
Hetzner berechnet Snapshots nach ihrer Größe. Mehr aufbewahrte Snapshots kosten mehr.
:::

## Snapshot von Hand

Bei eingeschaltetem Zeitplan legst du mit **Jetzt Snapshot erstellen** einen zusätzlichen Snapshot an, etwa vor einem größeren Update. Solche Snapshots löscht das Panel nie von selbst. Snapshots, die du in der Hetzner-Konsole angelegt hast, fasst das Panel ebenfalls nicht an.

## Snapshot zurückspielen

Das Zurückspielen eines Snapshots ersetzt den ganzen Server und läuft in der Hetzner Cloud Console über **Server → Snapshots → Wiederherstellen**. Das Panel bietet dafür bewusst keinen Knopf an, weil es dabei selbst überschrieben würde.
