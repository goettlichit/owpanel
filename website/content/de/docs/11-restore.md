---
title: Wiederherstellung
description: Webseiten zurückholen, fehlende Webseiten neu aufbauen und das Panel nach einem Totalausfall wiederherstellen.
group: Sicherung
icon: history
slug: restore
---

## Eine Webseite zurückholen

Unter **Webseite → Backups** siehst du alle Sicherungen der Webseite. Öffne eine Sicherung, um ihren Inhalt Ordner für Ordner anzusehen, und wähle, was zurückkommt:

| Bereich | Was passiert |
|---|---|
| Alles | Dateien und alle Datenbanken kommen auf den Stand der Sicherung |
| Nur Dateien | `htdocs` kommt auf den Stand der Sicherung; Dateien, die es damals nicht gab, verschwinden |
| Ordner oder Datei | Nur der gewählte Ordner oder die Datei kommt zurück, sonst ändert sich nichts |
| Nur Datenbanken | Alle Datenbanken der Webseite kommen zurück, die Dateien bleiben |
| Eine Datenbank | Nur die gewählte Datenbank kommt zurück |

Vor jeder Wiederherstellung legt das Panel eine **Sicherheits-Sicherung** an, in der Liste als **vor Wiederherstellung** markiert. Hast du das Falsche zurückgespielt, spielst du einfach diese Sicherung zurück. Die letzten fünf davon bleiben.

## Fehlende Webseiten neu aufbauen

Kennt das Panel eine Webseite, die es auf dem Server nicht mehr gibt, etwa nachdem das Panel auf einem neuen Server wiederhergestellt wurde, zeigt **Server → Backups** die Karte **Fehlende Webseiten**. **Aus Sicherungen neu aufbauen** baut jede fehlende Webseite in diesen Schritten auf:

1. Webseite anlegen, zunächst ohne Zertifikat
2. Datenbanken mit ihren gespeicherten Passwörtern anlegen
3. Dateien und Datenbanken aus der neuesten Sicherung einspielen
4. Cronjobs und SFTP-Zugang wieder einrichten
5. Zertifikat neu anfordern

Vorher spielt das Panel die eigenen Firewall-Regeln und fail2ban-Ausnahmen des Servers wieder ein. Jeder Schritt ist eine eigene Aufgabe mit Protokoll. Schlägt einer fehl, überspringt das Panel die restlichen Schritte dieser Webseite; die anderen laufen weiter. Eine einzelne Webseite baust du unter **Webseite → Backups** neu auf, auf Wunsch auch aus einer älteren Sicherung.

## Das Panel nach einem Totalausfall wiederherstellen

Ist der Server des Panels verloren, holst du das Panel auf einem neuen Server aus seiner Sicherung zurück. Du brauchst dafür die [Notfalldaten](docs:backups#notfalldaten).

1. **Neuen Server anlegen** mit Debian 13 und der Domain des alten Panels darauf zeigen lassen.
2. **Passwort des Subaccounts neu setzen:** In der Hetzner Console bei der Storage Box unter **Subaccounts** den Subaccount des alten Panel-Servers öffnen und ein neues Passwort vergeben. Das alte Passwort kennt das Panel nicht.
3. **Installer starten**, wie unter [Installation](docs:installation) beschrieben, und bei der ersten Frage **wiederherstellen** wählen. Der Installer fragt nach Storage Box, Subaccount, Passwort und Repository-Passwort aus den Notfalldaten und zeigt die letzten zehn Sicherungen zur Auswahl.
4. **Webseiten mit wiederherstellen:** Die Frage, ob er auch die Webseiten dieses Servers neu aufbauen soll, beantwortest du mit Ja. Sobald die Sicherungen verbunden sind, baut das Panel sie wie oben beschrieben auf.
5. **Anmelden** mit deinem alten Konto und deiner alten Zwei-Faktor-App.

Braucht der Server nach den Updates einen Neustart, wartet der Installer, bis die Webseiten fertig sind, und zeigt den Fortschritt alle 30 Sekunden. Mit Enter hörst du auf zu warten; der Neuaufbau läuft im Panel weiter.

::: info
Unter **System → Backup** steht danach, aus welcher Sicherung das Panel stammt. Den Hinweis kannst du mit dem Kreuz ausblenden; in der Liste der Panel-Sicherungen bleibt die Sicherung als **wiederhergestellt** markiert.
:::

## Was nicht gesichert wird

- Logs und der Ordner `tmp` einer Webseite beginnen nach einem Neuaufbau leer.
- Zertifikate werden neu angefordert, nicht zurückgespielt.

Ein [Snapshot](docs:snapshots) des alten Servers ist oft der schnellere Weg, wenn der Server selbst noch existiert.
