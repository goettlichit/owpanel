# Design

Das Panel soll ruhig, übersichtlich und ohne Einarbeitung bedienbar sein.

## Grundlage

- **UI-Kit:** [Tabler](https://github.com/tabler/tabler) mit dem Paket `@tabler/core`, basierend auf Bootstrap 5 und unter MIT-Lizenz
- **Schrift:** [Inter](https://github.com/rsms/inter) als Variable Font unter der SIL Open Font License 1.1
- **Darstellung:** System, Hell oder Dunkel, umschaltbar oben auf jeder Seite und am Konto gespeichert (E-055). Standard folgt der Systemeinstellung.
- **Bildschirme:** Desktop zuerst, Grundfunktionen auch auf dem Smartphone nutzbar

Die Laravel-Oberfläche übernimmt Tabler über Blade-Komponenten, zum Beispiel `<x-card>` und `<x-status-badge>`. Seiten verwenden diese Komponenten statt kopierter Tabler-Markup-Blöcke, damit spätere Designänderungen an einer Stelle möglich sind.

Tabler ist keine Alternative zu Bootstrap, sondern setzt darauf auf. Bootstrap-Klassen und -Komponenten bleiben nutzbar, Tabler ergänzt Admin-Layouts und das passende Erscheinungsbild.

## Regeln

1. **Alles lokal:** Schrift, CSS, JavaScript und Icons werden mit dem Panel ausgeliefert. Es gibt keine CDNs und kein Google Fonts. Das vermeidet Datenweitergabe an Dritte und hält das Panel unabhängig.
2. **Lizenzen mitliefern:** Die OFL-Lizenz der Schrift und die Hinweise weiterer Pakete liegen dem Release bei.
3. **Nur Benötigtes einbinden:** Wir nutzen `@tabler/core` und die Icons, nicht das gesamte Demo-Paket.
4. **Mitgelieferte Bibliotheken einzeln prüfen:** Tabler bündelt Drittbibliotheken mit eigenen Lizenzen. ApexCharts ist zum Beispiel seit Version 5 nicht mehr MIT-lizenziert und wird deshalb nicht ungeprüft übernommen.
5. **Texte nie im Markup:** Sichtbare Texte stehen in den Sprachdateien. Layouts müssen auch die oft längeren deutschen Wörter aufnehmen.
6. **Status nie nur über Farbe:** Zustände haben immer auch Text oder ein Icon.
7. **Anrede (E-132):** Kunden werden gesiezt, Administratoren geduzt. Seiten und E-Mails, die beide sehen (Anmeldung, Passwort, Zwei-Faktor, Mein Konto), siezen. Texte zu Funktionen, die auch Kunden nutzen (Datenbanken, Backups, SFTP, Cronjobs, Logs), kommen möglichst ohne Anrede aus. Die Anleitung auf owpanel.org duzt.
8. **Begriffe (E-134):** Auf Deutsch heißt es „Webseite“ und „Webseiten“, auf Englisch „Website“.
9. **Quellcode verlinken:** Das Panel zeigt dauerhaft einen Link zum Quellcode der laufenden Version an. Das unterstützt die Pflicht aus Abschnitt 13 der AGPL.

## Aufbau der Seiten

Der Kopfbereich folgt dem Auftritt von goettlich.it (E-057). Der Entwurf liegt als Design-Canvas „OWPanel – Logo und Kopfbereich“ vor.

- **Obere Zeile:** das Logo, daneben „Open Web Panel“. Rechts die Begrüßung nach Tageszeit mit Vornamen („Guten Abend, Alexander“), dann Sprache und Darstellung als reine Symbol-Knöpfe.
- **Zweite Zeile:** die Hauptbereiche mit Symbol und Text, der aktive blau unterstrichen. Rechts das Benutzermenü mit Initialen, vollem Namen und darunter klein der E-Mail-Adresse, die zugleich der Benutzername ist.
- **Oben stehen die Bereiche, links das Menü des gewählten Objekts.** Die Bereiche für Administratoren sind Übersicht, Websites, Kunden, Server und System; Kunden enthält Kunden und Pakete (E-125), System enthält Benutzer, Audit-Log, Panel-Logs, Einstellungen, Externe Dienste (E-123) und später die API. Aufgaben und Logs stehen beim Server oder bei der Website, zu der sie gehören (E-093). Wer einen Server, eine Website, einen Kunden oder System öffnet, findet links dessen Unterpunkte, etwa Zustand, Auslastung, Updates, Firewall, Aufgaben und Logs eines Servers, Übersicht, Stammdaten, Paket, Zugang und Kunde löschen eines Kunden (E-136) oder Übersicht, Webseite und Domain, DNS, Zertifikat, Datenbanken, Cronjobs, SFTP, Backups, Aufgaben, Logs und Webseite löschen einer Webseite (E-137). Löschen steht immer als letzter Menüpunkt. Jeder Bereich hat links ein Menü, auch wenn es bisher nur einen Eintrag hat, damit der Inhalt auf allen Seiten an derselben Stelle beginnt. Die Übersicht hat vorerst nur „Zusammenfassung“; Monitoring und Benachrichtigungen kommen später dazu.
- Ein Menüpunkt mit demselben Namen hat überall dasselbe Symbol, etwa Übersicht immer `layout-dashboard`.
- Bereiche erscheinen erst im Menü, wenn es sie gibt. Es gibt keine toten Links.
- Auf dem Smartphone entfallen Produktname und Begrüßung, das linke Menü steht über dem Inhalt.

### Info-Boxen

Info-Boxen sind Karten mit `tone="info"`, die erklären statt handeln: „Gut zu wissen“, „Aufbewahrungsregeln“, „Vor dem Anlegen“, „So geht es weiter“ (E-117). Karten mit Aktion wie „Testskript starten“ oder „Erneut versuchen“ zählen nicht dazu.

- Eine Info-Box steht immer **rechts oben**, in `col-lg-4`, neben dem ersten Hauptinhalt in `col-lg-8`. Die Zeile nutzt `align-items-start`, jede Karte ist so hoch wie ihr Inhalt.
- Statusmeldungen der Seite (etwa „Kein Backup-Ziel eingerichtet“) stehen darüber über die volle Breite.
- Gehören mehrere Karten zum Hauptinhalt, stehen sie links untereinander (`col-lg-8 d-flex flex-column gap-3`), etwa SFTP-Zugang und Passwort.
- Tabellen, Listen und weitere Formulare folgen darunter über die volle Breite.
- Fehlt der Hauptinhalt, etwa weil ein Formular für die Rolle nicht da ist, rückt die Info-Box trotzdem nach rechts (`ms-auto`).
- Auf dem Smartphone steht die Info-Box nach dem Hauptinhalt.

Vorbild sind die Seiten „Snapshots“ und „Backups“ eines Servers.

## Farben mit Bedeutung

Kacheln mit Kennzahlen (`<x-stat-card>`) haben einen farbigen oberen Rand. Die Farbe trägt eine feste Bedeutung, und dieselbe Aussage steht immer auch im Text:

| Farbe | Bedeutung | Beispiel |
|---|---|---|
| Blau | Information, keine Bewertung | Anzahl Benutzer, Kunden, Websites |
| Grün | In Ordnung | Alle Administratoren nutzen Zwei-Faktor |
| Orange | Beobachten | Fehlgeschlagene Anmeldungen in den letzten 24 Stunden |
| Rot | Aktion nötig, mit leicht rotem Hintergrund und Badge | Administrator ohne Zwei-Faktor, Sperre nach zu vielen Versuchen |

Rot bleibt selten, damit es auffällt. Eine Kachel wird nur rot, wenn jemand etwas tun muss.

## Stil

Diese Regeln gelten für alle Seiten (E-060). Sie folgen dem Stil des Kundenbereichs von goettlich.it.

**Karten nach Zweck**

Jede Karte hat oben eine farbige Linie, nur das linke Menü nicht (E-077). Den Kopf färben nur Karten, bei denen man aufpassen muss: sicherheitsrelevante Änderungen orange, nicht umkehrbare Aktionen rot. So fällt auf, was wichtig ist.

| Karte | Linie oben | Kopf | Knopf im Fuß | Beispiel |
|---|---|---|---|---|
| Hauptdaten, Tabellen | Blau | neutral | Blau, `btn-primary` | Persönliche Daten, Letzte Aufgaben |
| Ergänzende Angaben, harmlose Aktionen (`tone="info"`) | Blau | neutral | Blau | Vor dem Anlegen, Testskript starten |
| Erfolgreich eingerichtet (`tone="success"`) | Grün | neutral | je nach Aktion | Zwei-Faktor aktiv |
| Sicherheitsrelevante Änderung (`tone="warning"`) | Orange | orange getönt, Titel orange | Orange, `btn-warning` | Passwort ändern |
| Nicht umkehrbar (`tone="danger"`) | Rot | rot getönt, Titel rot | Rot, `btn-danger` | Website löschen, Server neu starten |

Karten, die einen Zustand zeigen, färben die Linie nach diesem Zustand (`accent`), mit den Bedeutungen aus „Farben mit Bedeutung“: Grün in Ordnung, Orange beobachten oder in Arbeit, Rot Problem, Blau noch unbekannt. Beispiele: „System“ eines Servers nach Online/Offline, „Website“ nach ihrem Status, die Karte „Server“ auf der Übersicht, die Details einer Aufgabe. Der Zustand steht immer auch als Text oder Badge in der Karte.

Für eingerichtete Funktionen gilt dabei (E-108): **Blau** nicht eingerichtet oder aus, **Grün** eingerichtet und in Ordnung, **Orange** in Arbeit (etwa „wird verbunden“), **Rot** die letzte Ausführung ist fehlgeschlagen (offener Alarm aus E-101). So färben sich unter System → Externe Dienste „Hetzner-API“ und „Hetzner-API für DNS“, unter System → E-Mail-Versand und System → Pocket ID die jeweilige Karte, unter System → Backup „Snapshots der Server“ und „Backups der Server“, unter Server → Snapshots „Zeitplan und Aufbewahrung“ und unter Server → Backups „Backup-Ziel“. Karten mit fester Bedeutung behalten ihre Farbe, etwa „Notfalldaten“ in Orange.

- Alle Karten und Kacheln haben einen leichten Schatten, im dunklen Modus etwas kräftiger. Derselbe Schatten liegt unter dem Kopfbereich.
- Aktionen eines Formulars stehen im grauen Fuß der Karte, rechts ausgerichtet, mit Symbol vor dem Text.
- Pflichtfelder haben einen roten Stern (`form-label required`). Nur lesbare Felder sind grau hinterlegt und haben einen Hinweis darunter.
- Gruppen von Karten bekommen eine Zwischenüberschrift mit einem Satz Erklärung, etwa „Anmeldung und Sicherheit“.

**Funktionen, die an oder aus sein können** (`<x-feature-card>`): farbiger oberer Rand (grün an, blau aus), Titel und kurze Beschreibung, rechts ein Badge „Aktiv“ oder „Aus“ in Großbuchstaben, darunter durch eine Linie getrennt die Erklärung und die Aktionen. Ist eine Funktion an, wirkt aber nur zum Teil (etwa die www-Adresse ohne Zertifikat), sind Rand und Badge orange, und das Badge nennt den Grund (`warning`).

**Breiten**

Karten richten sich nicht nach ihrem Text, sondern nach festen Breiten, damit Kanten bündig bleiben und die Sprache die Breite nicht ändert (E-061):

- Formulare und Textkarten höchstens halbe Breite (`col-lg-6`), zwei Formulare stehen nebeneinander.
- Funktionskarten immer halb breit, auch wenn nur eine da ist.
- Tabellen und Kennzahl-Kacheln über die volle Breite.
- Listen mit vielen Angaben je Eintrag, etwa die Serverliste, zeigen jeden Eintrag als eigenen Block: eine graue Beschriftungszeile, darunter die Werte, dann eine zweite graue Zeile nur über den weiteren Spalten. Die erste Spalte mit dem Namen bleibt darunter weiß, damit der Name leicht zu finden ist (E-077).
- Der Inhalt samt linkem Menü endet bei etwa 1400 Pixeln (`.ow-body`) und steht mittig im Fenster, die Fußzeile und der Inhalt des Kopfbereichs auch. Nur die Hintergründe des Kopfbereichs laufen über die ganze Breite (E-065, E-066).

**Hinweise**

Es gibt genau zwei Arten von Hinweisen (E-083):

1. **Rückmeldung auf eine Aktion** („gestartet“, „gespeichert“, „fertig“, „fehlgeschlagen“, „Bestätigung stimmt nicht“) ist immer ein Toast. Nie eine farbige Fläche im Inhalt.
2. **Zustand, der zur Seite gehört** („Neustart nötig“, „Für diese Datenbank läuft eine Aufgabe“, eine fehlgeschlagene Abfrage, ein einmal angezeigtes Start-Passwort) ist eine Karte im üblichen Stil mit farbiger Linie (`<x-card accent="…">`). Fehler in Formularen stehen am Feld.

Toasts (`<x-flash :toast="true">` im Hauptlayout, Livewire-Ereignis `owpanel-toast`) erscheinen oben mittig direkt unter dem Kopfbereich und schweben über dem Inhalt, damit nichts springt (E-065):

- Erfolg grün, schließt sich selbst: mindestens acht Sekunden, bei langen Texten länger (etwa 15 Zeichen je Sekunde, höchstens 20 Sekunden). Zeigt der Mauszeiger darauf oder liegt der Fokus darin, läuft die Zeit nicht weiter.
- Warnung orange und Fehler rot, bleiben stehen, bis sie geschlossen werden.
- Jede Meldung hat ein Kreuz zum Schließen. In der Session heißen sie `status`, `warning` und `error`.
- Dauerhafte Zustände wie ein Wartungsmodus sind keine Meldung, sondern ein Banner über die Inhaltsbreite.
- Auf den Anmeldeseiten stehen Meldungen über dem Formular.
- Serveraufgaben (E-152): Die Seite bleibt, wo sie ist. Der Knopf, der die Aufgabe gestartet hat, zeigt Spinner und Text (`data-ow-busy`, dazu `data-ow-task` mit dem Skript), auch nach erneutem Laden, solange die Aufgabe läuft. Findet die Seite keinen passenden Knopf, steht über dem Inhalt eine Zeile „… läuft“. Ist die Aufgabe fertig, lädt die Seite neu und zeigt für eigene Aufgaben einen grünen oder roten Hinweis, bei Fehlern für Administratoren mit Link „Details“ zum Protokoll. Es gibt keinen Start-Hinweis und keinen Sprung auf die Aufgabenseite. Ein kleines Abzeichen bei Cron, SFTP, Firewall und Backups erscheint nur noch, wenn der letzte Abgleich fehlgeschlagen ist. Fehler einer Aufgabe stehen zusätzlich als Zeile in ihren Details.
- Zurück geht es immer über den blauen Knopf oben rechts (`btn-outline-primary` mit Pfeil). Die Seite einer Aufgabe hat ihn zu dem Objekt, um das es ging (Website, Datenbank, Updates oder Server; bei Export und Import die Seite „Export/Import“). Es gibt keine automatische Rückkehr und keinen zweiten Zurück-Knopf im Inhalt.
- Was nach einer Aufgabe zu tun ist, zeigt die Seite des Objekts nach dem Neuladen und steht zusätzlich in den Details der Aufgabe, etwa wo die Datei eines Exports liegt. Heruntergeladen wird auf der Seite des Objekts, nicht auf der Aufgabenseite.

**Fußzeile**

Die Fußzeile ist ein eigenes Band in der Flächenfarbe des Kopfbereichs, mit Linie oben (E-067). Administratoren sehen drei Spalten: „Über OWPanel“ mit einem Satz Beschreibung, „Hilfe“ mit Dokumentation, Änderungen und „Fehler melden“, „System“ mit Panel-Version, PHP-Version und Zeitzone der Anzeige. Darunter steht für alle, auch vor der Anmeldung, mittig die Zeile mit Hersteller, Version, Quellcode-Link und Lizenz. „goettlichIT“ verweist auf https://goettlich.it, „OWPanel“ später auf die Projekt-Homepage (E-073). Kunden sehen nur diese Zeile; Impressum und Datenschutz des Anbieters kommen mit den Einstellungen in Meilenstein 3 dazu.

**Anmeldeseiten**

Anmeldung, Zwei-Faktor-Abfrage und Passwortbestätigung haben dieselbe erste Kopfzeile wie das Panel (`<x-topbar>`): Logo, „Open Web Panel“, Sprache und Darstellung, ohne Begrüßung und Hauptmenü. Darunter steht die Karte mittig (E-066).

**Tabellen**

- Spaltenköpfe in kleinen Großbuchstaben, Gruppenzeilen grau hinterlegt („Administratoren“, „Kunden“).
- Status und Rollen als helle Badges (`<x-badge>`): Admin rot, Kunde blau, aktiv grün, aus grau, ausstehend orange.
- Art und Auslöser als helle Badges, überall gleich (E-108): **automatisch** blau, **von Hand** grün, **vor Wiederherstellung** orange; bei Snapshots „vom Panel“ blau, „von Hand“ grün, „eigener“ grau. Ein Merkmal, das fehlt oder Aufmerksamkeit braucht, ist orange (etwa „fehlt auf dem Server“).
- Ergebnisse von Aufgaben, Sicherungen und Snapshots zeigen dieselben Zustände (`<x-status-badge>`): Wartend grau, Läuft blau mit drehendem Symbol, Erfolgreich grün, Fehlgeschlagen rot. Eigene Wörter wie „Fertig“ gibt es dafür nicht.
- Personen zweizeilig: Name, darunter klein die E-Mail-Adresse.
- Aktionen als Knöpfe mit Rand und Symbol: Bearbeiten blau, Sperren orange, Löschen rot. Ist eine Aktion nicht möglich, ist ihr Knopf ausgegraut statt versteckt.
- Einträge in Tabellen öffnen sich über ihren Namen oder ihre Nummer als Link, etwa die Kundennummer, die Domain, den Namen einer Aufgabe oder die Aktion im Audit-Log. Eine eigene Schaltfläche mit Auge zum Öffnen gibt es nicht (E-122, E-131). Das Auge steht nur für „Passwort anzeigen“.
- Zeitpunkte zweizeilig: Datum, darunter klein die Uhrzeit (`<x-time :stacked="true">`). Lange E-Mail-Adressen dürfen umbrechen, damit kein waagerechter Scrollbalken entsteht.

## Logo

Das Logo ist die „Plakette“ (E-058): ein runder Kreis wie beim goettlichIT-Logo, darin „ow“ in Textfarbe und darunter „panel“ in Blau. Es folgt dem hellen und dunklen Modus. Das Favicon ist ein blauer Kreis mit weißem „ow“. Die Blade-Komponente heißt `<x-logo>`, das Favicon liegt unter `panel/public/favicon.svg`.

## Wichtige Bildschirme

| Bildschirm | Zweck |
|---|---|
| Übersicht | Server, Websites und aktuelle Aufgaben |
| Serverdetails | Status, Dienste, Firewall mit TCP- und UDP-Regeln, Logs |
| Website-Liste | Domain, Typ, SSL- und HTTP/3-Status, Server |
| Website-Details | Einstellungen, Datenbanken, Cronjobs, Backups, Logs |
| Aufgabe | Fortschritt und Log einer Serveraktion |
| Einstellungen | Benutzer, Sprache, Pocket ID |
| Kunden | Kundenliste, Konten, zugeordnete Websites |
| API und Webhooks | Tokens, Rechte, Webhook-Ziele, Zustellungen |

### Kundenbereich

| Bildschirm | Zweck |
|---|---|
| Meine Websites | Eigene Websites mit Status und SSL, Verbrauch des Pakets als Balken |
| Website-Details | Übersicht, Datenbanken, Cronjobs, Backups, Logs, SFTP-Zugang und DNS-Prüfung |
| Konto | Name, Sprache, Passwort, Zwei-Faktor, Pocket ID |

Der Kundenbereich nutzt dieselben Komponenten, aber eine eigene Navigation (E-124): Das Hauptmenü hat nur „Meine Webseiten“; „Mein Konto“ und „Abmelden“ stehen wie bei Administratoren im Menü oben rechts. Er verwendet keine Serverbegriffe wie Servername oder Firewall; nur die SFTP-Zugangsdaten nennen Host und Benutzer. Eine gesperrte Website zeigt den Hinweis, sich an den Anbieter zu wenden, ohne den Grund zu nennen (E-127).

Der Speicherplatz erscheint als Balken für den Kunden und je Website: blau bis 90 %, orange ab 90 %, rot ab 100 % mit dem Hinweis, Platz freizugeben oder den Anbieter zu kontaktieren. Eine Info-Box erklärt, dass Datenbanken mitzählen (E-126).

Solange nur ein Server verbunden ist, blendet die Oberfläche Serverauswahl und Serverspalten aus. So wirkt das Panel im Standalone-Betrieb nicht unnötig komplex. Ausnahme: Die Liste „Alle Websites“ zeigt die Spalte „Server“ immer (E-122).
