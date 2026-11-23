---
title: Kunden und Benutzer
description: Kunden anlegen, Webseiten zuordnen und Administratoren verwalten.
group: Betrieb
icon: users
slug: customers
---

## Kunden

Unter **Kunden** legst du Kunden an und ordnest ihnen Webseiten zu. Das Panel speichert dabei nur, was es für den Betrieb braucht: Vorname, Nachname, optional die Firma, E-Mail-Adresse und Sprache. Die Kundennummer vergibt das Panel selbst; dazu kannst du die Nummer eintragen, unter der dein eigenes System den Kunden führt.

Abrechnung, Verträge und weitere Stammdaten gehören nicht ins Panel, sondern in dein eigenes System. Es wird später über eine REST-API und Webhooks angebunden.

## Kundenbereich

Was deine Kunden dort sehen und tun, beschreibt die Doku [Für Kunden](docs:customer-start) in der Sie-Form. Das Panel verlinkt Kunden im Fuß jeder Seite unter **Hilfe** dorthin. Hast du eine eigene Hilfeseite, trägst du sie unter **System → Einstellungen** ein; dann führt der Link dorthin.

Hat ein Kunde einen Zugang (Kunde → **Zugang**), sieht er nach der Anmeldung **Meine Webseiten** mit den Webseiten, die ihm zugeordnet sind. Je Webseite bekommt er ein eigenes Menü:

- **Übersicht:** Domain, Zertifikat anfordern und die www-Adresse ein- oder ausschalten.
- **DNS:** Prüfung, ob die Einträge auf den Server zeigen, mit den Soll-Adressen. Ändern kann er dort nichts; das macht er bei seinem DNS-Anbieter.
- **SFTP:** Zugang ein- und ausschalten, Passwort anzeigen oder neu erzeugen, Schlüssel hinterlegen.
- **Datenbanken:** anlegen und löschen, Zugangsdaten und Passwort, neues Passwort, Datenbank-Werkzeug mit Tabellen und SQL, Export und Import.
- **Cronjobs:** anlegen, ändern, pausieren und löschen.
- **Backups:** Sicherungen ansehen, **Jetzt sichern** und ganz oder teilweise wiederherstellen, zusammen höchstens so oft je Tag, wie sein Paket erlaubt. Die automatische Sicherung schaltest nur du ein oder aus.
- **Logs:** Access-, Error-, PHP- und Cron-Log seiner Webseite.

Server, Linux-Pfade, Aufgaben-Logs und andere Kunden sieht er nicht, und die Seiten der Verwaltung bleiben für ihn gesperrt. Neue Datenbanken und Cronjobs legt er nur im Rahmen seines Pakets an, ohne Paket gar nicht; einen Cronjob, den du über die Grenze hinaus erlaubt hast, kann er nur noch löschen. Was ein Kunde ändert, steht mit seinem Namen im Audit-Log.

## Zuordnung und Pakete

Jede Webseite gehört entweder einem **Kunden**, der **Administration** oder ist **nicht zugeordnet**. Das legst du beim Anlegen fest oder später unter **Webseite und Domain → Zuordnung**. Webseiten der Administration sind deine eigenen, etwa deine Homepage. Die Liste aller Webseiten zeigt sie in drei Gruppen, durch eine Linie getrennt: Administration, nicht zugeordnet und Kunden.

Unter **Kunden → Pakete** legst du fest, wie viel Speicherplatz und wie viele Webseiten, Datenbanken und Cronjobs ein Kunde haben darf und wie oft seine Cronjobs höchstens laufen. Im Abschnitt **PHP** stehen die Höchstwerte für Arbeitsspeicher, Upload-Größe und Laufzeit jeder Webseite sowie die Zahl der PHP-Prozesse je Webseite; ein neues Paket beginnt mit 512 MB, 128 MB, 300 Sekunden und 10 Prozessen. Der Arbeitsspeicher einer Webseite ist damit höchstens Prozesse × Arbeitsspeicher. Änderst du diese Werte, gelten sie sofort für alle Webseiten der Kunden mit dem Paket; zu hohe Einstellungen werden gekürzt. Die Grenzen gelten nur für Webseiten, die einem Kunden mit Paket zugeordnet sind, und zählen über alle seine Webseiten. Webseiten der Administration und nicht zugeordnete Webseiten haben keine Grenzen; bei nicht zugeordneten und bei Kunden ohne Paket weist das Panel darauf hin.

Überschreitest du als Administrator eine Grenze, etwa mit einem Cronjob alle 5 Minuten bei einem stündlichen Paket, zeigt das Panel die betroffene Grenze und fragt nach. Mit **Trotzdem speichern** geht es weiter; das steht im Audit-Log. Kunden können eine Grenze nicht überschreiten, und einen Cronjob über der Grenze können sie nur noch löschen.

## Speicherplatz

Der Speicherplatz eines Pakets gilt für Dateien und Datenbanken aller Webseiten eines Kunden zusammen. Gemessen wird alle 15 Minuten auf jedem Server.

- **Kunde:** Unter **Meine Webseiten** sieht er einen Balken mit belegt, Grenze und frei, aufgeteilt in Dateien und Datenbanken, und in der Liste die Belegung je Webseite. Ab 90 % wird der Balken orange, ab 100 % rot, jeweils mit einem Hinweis, Platz freizugeben oder sich bei dir zu melden. Ist der Speicherplatz voll, sind neue Datenbanken und Importe ausgegraut.
- **Du:** Unter Kunde → **Paket** siehst du denselben Balken und die Belegung je Webseite. Die Liste aller Webseiten hat die Spalte **Belegt**, und die Übersicht jeder Webseite zeigt Dateien und Datenbanken, auch für Webseiten der Administration und nicht zugeordnete, dort ohne Grenze. Ist ein Kunde voll, bekommst du die Benachrichtigung **Speicher eines Kunden voll** und eine Entwarnung, sobald wieder Platz ist.

Die Dateien begrenzt das Dateisystem hart über Linux-Quotas: Jede Webseite darf ihre eigenen Dateien plus den freien Platz des Kunden belegen. Ist alles voll, können seine Webseiten keine Dateien mehr schreiben, etwa Uploads oder Caches. Datenbanken werden nur gemessen. Die Grundeinrichtung schaltet die Quotas auf ext4 ein und ändert dafür `/etc/fstab`, mit Prüfung und Rücknahme bei einem Fehler. Auf anderen Dateisystemen misst das Panel nur und weist darauf hin, dass die Grenze nicht durchgesetzt wird. Die Festplatte des ganzen Servers siehst du weiter auf der Seite des Servers.

## Webseiten sperren

Unter Webseite → **Sperren** sperrst du eine Webseite, etwa bei einer offenen Rechnung. Eine interne Notiz hilft dir später; der Kunde sieht sie nicht. Wahlweise sperrst du gleich den Zugang des Kunden mit.

- **Besucher** sehen eine neutrale Seite „vorübergehend nicht erreichbar“ mit HTTP 503, ohne Grund. Suchmaschinen erkennen daran, dass es nur vorübergehend ist.
- **Auf dem Server** ruhen PHP, Cronjobs und SFTP, offene SFTP-Verbindungen werden getrennt. Dateien, Datenbanken und Backups bleiben unverändert, und das Zertifikat wird weiter verlängert.
- **Der Kunde** sieht im Kundenbereich „Gesperrt“ und den Hinweis, sich bei dir zu melden. Er kann alles ansehen, aber nichts ändern.
- **Du** kannst eine gesperrte Webseite entsperren, löschen, sichern, wiederherstellen und ihre Datenbanken exportieren. Alles andere geht erst nach dem Entsperren; die Seiten der Webseite zeigen dazu einen roten Hinweis.

**Entsperren** stellt die Webseite genau so wieder her, wie sie vorher war. Einen mitgesperrten Zugang entsperrst du getrennt unter Kunde → **Zugang**.

## Administratoren

Unter **System → Benutzer** legst du weitere Administratoren an. Sie bekommen ein Start-Passwort, das du ihnen auf sicherem Weg weitergibst, und richten beim ersten Anmelden die Zwei-Faktor-Authentifizierung ein. Administratoren dürfen im Panel alles.

## Audit-Log

Unter **System → Audit-Log** steht, wer wann was getan hat: Anmeldungen, angelegte und gelöschte Webseiten, geänderte Firewall-Regeln, angezeigte Passwörter, gestartete Sicherungen und mehr.
