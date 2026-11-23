---
title: DNS
description: DNS-Einträge einer Webseite prüfen, bei Hetzner anlegen, korrigieren und beim Löschen entfernen.
group: Betrieb
icon: world-search
slug: dns
---

Damit eine Webseite erreichbar ist und ein Zertifikat bekommt, müssen ihre DNS-Einträge auf den Server zeigen. Die Seite **DNS** im Menü jeder Webseite zeigt, ob das stimmt. Liegt die Domain in einer Zone der [Hetzner Cloud](https://www.hetzner.com/cloud), legt das Panel fehlende Einträge auch selbst an. Die Seite sehen nur Administratoren.

## Was die Seite zeigt

Das Panel betrachtet nur die Einträge, die eine Webseite braucht: **A** (IPv4) und **AAAA** (IPv6) für die Domain und, falls eingeschaltet, für die www-Adresse. MX, TXT und andere Einträge pflegst du weiter beim DNS-Anbieter.

Alle Spalten zeigen die IP-Adresse, auf die der Eintrag zeigt, nur von verschiedenen Stellen aus gesehen. Weichen sie voneinander ab, sieht man, wo es hängt.

| Spalte | Bedeutung |
|---|---|
| Soll | die Adressen des Servers |
| Hetzner-Zone | was in der Zone bei Hetzner eingetragen ist, mit TTL (nur wenn das Panel die Zone über die API findet) |
| Antwort Nameserver | was die zuständigen Nameserver der Domain gerade ausliefern; das Panel fragt sie direkt |
| Antwort Resolver | was ein normaler Resolver mit Zwischenspeicher antwortet, hier der des Panel-Servers; so fragen auch Browser und Let's Encrypt |

Jeder Eintrag bekommt einen Zustand:

- **korrekt:** Nameserver und Resolver zeigen genau auf den Server.
- **wird verteilt:** Die Nameserver sind richtig, Resolver kennen noch den alten Stand. Das erledigt sich spätestens nach Ablauf der alten TTL.
- **fehlt:** Es gibt keinen Eintrag.
- **abweichend:** Der Eintrag zeigt auf eine andere Adresse oder enthält zusätzlich eine.
- **unbekannt:** Niemand hat geantwortet.

Antworten bleiben fünf Minuten zwischengespeichert. **DNS prüfen** fragt sofort neu. Die Prüfung über die Nameserver funktioniert mit jedem DNS-Anbieter, nicht nur mit Hetzner.

## Hetzner-Token einrichten

Zum Anlegen und Korrigieren braucht das Panel einen API-Token des Hetzner-Projekts, in dem die DNS-Zonen liegen. Du trägst ihn unter **System → Externe Dienste** ein.

- Liegen Server und Zonen im selben Projekt, genügt der Token unter **Hetzner-API**, den das Panel auch für Snapshots nutzt.
- Liegen die Zonen in einem eigenen Projekt, trägst du dessen Token unter **Hetzner-API für DNS** ein. Das ist zum Beispiel nach der Umstellung der alten Hetzner DNS Console oft so: Hetzner hat die Zonen dabei in ein Projekt „DNS Migrated“ übernommen.

Zum Anzeigen genügt ein Token mit Leserechten, zum Anlegen und Korrigieren braucht er **Lesen und Schreiben**. Das Panel prüft den Token beim Speichern und speichert ihn verschlüsselt.

## Einträge anlegen und korrigieren

Findet das Panel die Zone, steht in der Tabelle bei fehlenden Einträgen **Anlegen** und bei abweichenden **Korrigieren**, in der Übersicht zusätzlich **Alle korrigieren**. Jede dieser Schaltflächen führt erst auf eine Bestätigungsseite mit dem jetzigen und dem neuen Wert. Dort wählst du die TTL (5 Minuten bis 1 Tag). Geändert wird erst mit **Einträge setzen**.

Danach steht in jedem Eintrag genau die Adresse des Servers; eine zweite Adresse im selben Eintrag ersetzt das Panel. Jede Änderung steht im Audit-Log, mit altem und neuem Wert.

::: tip
Zeigen die Nameserver auf den Server, fehlt aber noch das Zertifikat, bietet die Seite oben **Zertifikat anfordern** an. Das passiert, wenn eine Webseite angelegt wurde, bevor die DNS-Einträge stimmten.
:::

## Beim Anlegen einer Webseite

Mit Hetzner-Token zeigt das Formular **Neue Webseite** die Option **DNS-Einträge bei Hetzner anlegen**. Sie ist vorausgewählt. Das Panel legt dann die fehlenden Einträge an, bevor es die Webseite einrichtet, sodass das Zertifikat gleich klappt. Vorhandene Einträge überschreibt es dabei nicht; zeigt einer woandershin, sagt die Meldung danach Bescheid, und du korrigierst ihn auf der Seite **DNS**.

## Beim Löschen einer Webseite

**Webseite löschen** im Menü der Webseite führt auf eine Bestätigungsseite. Sie listet die DNS-Einträge, die das Panel für diese Webseite gesetzt hat:

- **kann weg:** Der Eintrag zeigt noch genau auf die Adresse, die das Panel gesetzt hat.
- **bleibt:** Jemand hat den Eintrag inzwischen geändert.

Mit dem Häkchen **Die vom Panel angelegten DNS-Einträge mitlöschen** entfernt das Panel die Einträge mit „kann weg“, sobald die Webseite auf dem Server gelöscht ist. Ohne Häkchen bleibt alles stehen. Einträge, die du selbst beim DNS-Anbieter angelegt hast, rührt das Panel nie an.
