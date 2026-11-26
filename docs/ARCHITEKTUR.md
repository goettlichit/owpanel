# Architektur

Stand: Meilenstein 7 (Kundenbereich). Die Serverskripte unter `server/`, das Panel unter `panel/` und der Installer sind umgesetzt. Wo nicht anders vermerkt, beschreibt das Dokument den geplanten Aufbau.

## Überblick

```text
Browser
   │ HTTPS
   ▼
Panel (Laravel) ── MariaDB (Panel-Datenbank)
   │
   ▼
Queue-Worker
   │ SSH als Benutzer owpanel-agent
   ▼
Verwalteter Server
   └── sudo /opt/owpanel/bin/<freigegebenes Skript>
          ├── NGINX
          ├── PHP-FPM
          ├── MariaDB
          ├── Certbot
          └── Firewall
```

Das Panel ändert Server nie direkt aus einer Web-Anfrage heraus. Jede Serveraktion wird zu einem Job, den ein Queue-Worker ausführt.

## Standalone und Multi-Server

Es gibt nur einen Codepfad. Die Ersteinrichtung ergibt immer eine Standalone-Installation: Das Panel verwaltet genau einen Server, nämlich den eigenen. Auch dann läuft die Steuerung per SSH über `127.0.0.1` und denselben Benutzer `owpanel-agent`. Welcher Server das Panel trägt, steht in der Spalte `servers.hosts_panel`, nicht in seiner Adresse; der Installer setzt sie mit `owpanel:add-server --panel`, und es gibt höchstens einen solchen Server (E-192).

Für Multi-Server gibt es keinen Modus und keine Migration. Sobald ein zweiter Server eingebunden wird, erscheint er als weiterer Eintrag in `servers`, und die Oberfläche zeigt die Serverauswahl an.

Damit das funktioniert, gelten von Anfang an diese Regeln:

- Jede Website, Datenbank und Aufgabe gehört über `server_id` zu einem Server.
- Jede Website gehört über `customer_id` einem Kunden, über `admin_owned` der Administration oder ist nicht zugeordnet (E-084, E-139).
- Kein Code geht davon aus, dass das Panel auf dem Zielserver läuft.
- Das Panel braucht für Multi-Server eine von außen erreichbare HTTPS-Adresse, die Einrichtung soll sie deshalb früh abfragen.
- Die Panel-Datenbank bleibt von Kundendatenbanken getrennt, auch wenn beide im selben MariaDB-Dienst liegen.
- Die NGINX-Konfiguration des Panels liegt getrennt von denen der Websites.

### Geplant: Panel-Server als Eingangstor

Grundlage sind E-161 bis E-168; umgesetzt wird nach dem ersten Release (E-169).

```text
Besucher ── HTTPS, HTTP/3 ──► Panel-Server (Gateway, Zertifikate, ACME)
                                 │ privates Hetzner-Netz (IPv4)
                 ┌───────────────┼────────────────┐
                 ▼               ▼                ▼
          Websites auf     Server 2: Websites   Server 3: Websites
          dem Panel-Server  und Docker-Apps      und Docker-Apps
Kunden ── SFTP (Port 22, Hostname des Servers) ──► Server 2, Server 3
```

- Alle Domains zeigen auf die Eingangsadressen: eine gemeinsame IPv4 und je Website eine IPv6 aus einem Präfix (E-162). Zuerst sind das die Adressen des Panel-Servers, für ein zweites Gateway Floating IPs (E-164).
- Das Gateway beendet TLS und QUIC und reicht jede Anfrage an „Server + Port“ weiter: an den NGINX der Website auf ihrem Server oder an eine Docker-App (E-163). Der NGINX auf dem Website-Server übernimmt die Besucheradresse nur vom Gateway (`set_real_ip_from`), damit Logs, Statistik und PHP die echte Adresse sehen.
- Weitere Server kommen mit `bash get-owpanel --join <Panel-Adresse>` dazu (E-166), nur im selben privaten Hetzner-Netz. Das Panel steuert sie über die private Adresse. Öffentlich ist dort nur Port 22 für SSH und SFTP offen, alles andere nur aus dem privaten Subnetz. Ausgehend nutzen sie ihre eigene IPv4 und IPv6.
- Websites liegen auf genau einem Server. Beim Anlegen schlägt das Panel den Server mit der meisten Reserve vor; ein Paket kann die erlaubten Server vorgeben (E-161). Ein Umzug ändert nur das Ziel im Gateway (Meilenstein 5.1).
- Standalone ist der Fall, in dem Gateway und Website-Server derselbe Server sind. Der Gateway-Modus kommt über ein Update der wiederholbaren Skripte, ohne Migration.
- Serveraufgaben laufen je Server nacheinander und für verschiedene Server parallel; die Statusabfrage läuft als Job je Server.

## Ablauf: Ersteinrichtung

Die Ersteinrichtung läuft vollständig im Terminal (E-069). Einen Einrichtungsassistenten im Browser gibt es nicht.

1. Der Nutzer startet als root das Startskript `get-owpanel`. Es lädt Release-Archiv, Prüfsumme und Signatur über HTTPS, prüft beide, entpackt das Archiv und startet `./install` (E-071). Die Signatur prüft es mit `ssh-keygen -Y verify` gegen den eingetragenen öffentlichen Schlüssel; ein Release ohne gültige Signatur bricht ab, ein Testbau ohne Signatur warnt nur (E-167). Ein selbst geladenes Archiv lässt sich auch von Hand prüfen und entpacken.
2. Der Installer prüft Debian-Version und Archiv und fragt Domain des Panels, Hostname (nur bei der ersten Installation, E-150), Zeitzone und ersten Administrator ab (Vor- und Nachname, E-Mail, Passwort). Alles lässt sich auch als Option übergeben; ohne `--hostname` bleibt der Hostname bei einem Lauf ohne Rückfragen unverändert.
3. Er prüft, dass die Domain auf den Server zeigt. Let's Encrypt prüft sie später über Port 80, ein falscher DNS-Eintrag soll vorher auffallen.
4. Er legt `ow10000` und `owpanel-worker` an und erzeugt den SSH-Schlüssel des Panels (E-070).
5. Er richtet `owpanel-agent` ein (`setup-agent`), startet die Grundeinrichtung (`server-provision`) und bestätigt die Firewall über SSH wie das Panel (E-044). Danach setzt er die Zeitzone (`server-timezone`, E-046).
6. Er legt Datenbank und Datenbankbenutzer an, kopiert das Panel nach `releases/<Version>`, schreibt `.env`, führt die Migrationen aus und schaltet `current` um.
7. Er richtet PHP-FPM und NGINX ein, holt das Zertifikat und aktiviert HTTPS mit HTTP/3.
8. Er legt den Administrator an, trägt den eigenen Server als ersten Server ein und startet den Queue-Worker.
9. Der Administrator meldet sich unter `https://<Domain>` an und richtet dabei die Zwei-Faktor-Anmeldung ein, die das Panel für Administratoren erzwingt.

Ein zweiter Lauf repariert die Installation und behält Daten, Schlüssel und Konten. Mit einem neueren Archiv stellt er das Panel auf das neue Release um.

## Das Panel auf dem Server

```
/var/www/ow10000/                 root:ow10000 0750
├── releases/<Version>/panel/     Code, gehört root, für das Panel nur lesbar
├── current -> releases/<Version> aktives Release, wird in einem Schritt umgestellt
├── shared/.env                   Einstellungen und Geheimnisse, root:ow10000 0640
├── shared/storage/               schreibt das Panel (Logs, Sitzungsreste, Views)
│   └── app/transfers/            Export- und Importdateien von Datenbanken, gemeinsam mit owpanel-worker (E-082)
└── tmp/                          Uploads und temporäre Dateien von PHP
/var/lib/owpanel-worker/ssh/      SSH-Schlüssel, nur für owpanel-worker lesbar
/etc/owpanel/panel.conf           Domain, Zeitzone und Version der Installation
```

- Webanfragen laufen in einem eigenen PHP-FPM-Pool als `ow10000`, mit `open_basedir` auf `/var/www/ow10000/` und ohne Funktionen zum Starten von Prozessen. NGINX führt nur `index.php` aus.
- Der Abfrage-Worker für das Datenbank-Werkzeug läuft als eigener Dienst `owpanel-query` unter `owpanel-worker`, nur für die Queue `queries`, mit dem Log `query.log` (E-079).
- Die Auftrags-Worker laufen als systemd-Vorlage `owpanel-queue@.service` (Instanzen `@1` bis `@N`, zusammen `owpanel-queue.target`, `N` aus `OWPANEL_QUEUE_WORKERS`, Standard 3) unter `owpanel-worker`. Sie lesen Code und `.env` über die Gruppe `ow10000` und schreiben das Log `worker.log` (E-070). Je Server läuft höchstens eine Aufgabe; `servers.busy_task_id` nennt sie, ein weiterer Auftrag für denselben Server wartet 5 Sekunden und kommt dann wieder (E-191).
- System → Dienste zeigt, ob beide Worker und der Zeitplaner arbeiten: Der Zeitplaner legt jede Minute einen Prüfauftrag in jede Queue, die Worker merken sich die Zeit, zu der sie ihn ausführen. Neustart über `queue:restart`, Anhalten und Fortsetzen über `queue:pause` und `queue:resume`; ein echtes Stoppen gibt es im Panel nicht (E-133).
- Der Zeitgeber `owpanel-scheduler.timer` startet jede Minute `owpanel-scheduler.service`, also `php artisan schedule:run` unter `owpanel-worker`. Der Scheduler fragt jeden Server mit `server-status` ab und löscht einmal täglich Messwerte, die älter als sieben Tage sind (E-075). Stündlich fragt er erreichbare Server zusätzlich mit `server-updates` und `site-certificates` ab (E-076). „Jetzt prüfen“ startet `server-upgrade --refresh` (`apt-get update`), „Alle installieren“ `server-upgrade --install` (`apt-get dist-upgrade` mit `--force-confold`, wartet bis zu 10 Minuten auf die apt-Sperre); nach beiden liest der Scheduler den Stand neu (E-154). Einmal am Tag fragt `owpanel:release-check` die neueste Version des Panels bei GitHub ab.
- Der Code gehört root. Eine Lücke im Panel kann weder den eigenen Code noch die vorgefertigten Dateien in `bootstrap/cache` ändern.
- Die NGINX-Konfiguration des Panels liegt in `/etc/nginx/conf.d/owpanel-panel.conf`, getrennt von den Websites (E-041).

Grundeinrichtung und Rechte stammen aus denselben Skripten wie bei weiteren Servern. Weitere Server richtet derselbe Installer mit `--join` ein, ohne die Teile des Panels (E-166).

## Komponenten

| Komponente | Aufgabe |
|---|---|
| Weboberfläche | Formulare, Status, Logs, Sprachauswahl |
| Domänenlogik | Regeln für Server, Websites, Datenbanken und Backups |
| Queue-Jobs | Lange Aktionen im Hintergrund ausführen |
| SSH-Client | Freigegebene Skripte starten und Ausgaben erfassen |
| `get-owpanel --join` | Weiteren Server mit dem Installer einrichten und beim Panel anmelden (geplant, E-166) |
| `server/provision/` | Agent-Benutzer anlegen (`setup-agent`), Dienste wiederholbar konfigurieren (`modules/`) |
| `server/lib/` | Gemeinsame Bash-Funktionen |
| `server/bin/` | Per `sudo` freigegebene Einzelaktionen, z. B. `server-provision` und `site-create` |
| `server/templates/` | Konfigurationsvorlagen |

| Hetzner-Anbindung | Snapshots über die Hetzner Cloud API, Storage-Box-Subaccounts über `api.hetzner.com` (E-095, E-097, E-105) |
| Benachrichtigungen | Probleme und Ereignisse per E-Mail und Web-Push an Administratoren (`App\Notify`, `App\Push`, E-101, E-104) |

SSH aus PHP läuft über `phpseclib` mit festgelegtem Host-Schlüssel je Server (E-051). Die Queue nutzt den Treiber `database` (E-052), interaktive Teile der Oberfläche Livewire (E-049).

## Ablauf: Sicherung und Wiederherstellung

Grundlage sind E-095 bis E-100, E-103 und E-105; die Sicherheitsregeln stehen in `docs/SICHERHEIT.md`, Abschnitt „Sicherungen“.

1. **Snapshots:** `owpanel:snapshots` startet zur Snapshot-Uhrzeit je Server einen Hetzner-Snapshot über die Cloud API und löscht alte nach der Aufbewahrung.
2. **Backup-Ziel:** Unter Server → Backups wählt der Administrator eine Storage Box des Hetzner-Projekts. Das Panel legt über die API einen Subaccount an, dessen Verzeichnis `owpanel/<Server-ID>` nur dieser Server sieht, und ruft `backup-setup` mit einem Einmalpasswort auf; der Server hinterlegt seinen Schlüssel und legt das restic-Repository `restic` an.
3. **Sicherung:** `owpanel:backups` startet ab der Backup-Uhrzeit täglich oder alle 12, 6, 3 oder 1 Stunden je Website `site-backup` und auf dem Panel-Server `panel-backup`, nacheinander als Aufgaben. restic räumt danach nach der Aufbewahrung auf.
4. **Einblick und Wiederherstellung:** Listen und Inhalte einer Sicherung liest der Query-Worker live (`site-backups`, `site-backup-files`, `panel-backups`, `panel-backup-files`); `site-restore` holt eine Website ganz oder teilweise zurück und sichert vorher den aktuellen Stand.
5. **Panel verloren:** Der Installer bietet bei einer ersten Installation die Wiederherstellung aus der Panel-Sicherung an (`docs/WIEDERHERSTELLUNG.md`).

## Ablauf: Benachrichtigungen

1. `owpanel:notifications` vergleicht jede Minute den Zustand, den das Panel kennt (Status, Aufgaben, Snapshots, Zertifikate), mit offenen Problemen in `alerts`: neue werden einmal gemeldet, behobene einmal entwarnt (E-101).
2. Ereignisse wie fertige Sicherungen, Neustarts, eingespielte Updates oder fehlgeschlagene Aufgaben meldet `App\Notify\Events` dort, wo das Panel davon erfährt (E-104).
3. Jede Nachricht geht an die Administratoren, die Anlass und Weg gewählt haben. In ihren Ruhezeiten wartet sie in `pending_notifications` und kommt danach gesammelt.
4. Zugestellt wird über die Queue: E-Mail mit dem Mailserver aus den Einstellungen, Push verschlüsselt nach RFC 8291 an den Push-Dienst des Browsers, nur an bekannte Dienste. Das Panel ist dafür als Web-App installierbar (`manifest.webmanifest`, `sw.js`).

## Ablauf: Serveraufgabe

1. Eine Aktion im Panel legt in `server_tasks` eine Aufgabe mit Status `pending` an, schreibt einen Eintrag ins Audit-Log und gibt den Job `RunServerTask` in die Queue. Die Web-Anfrage selbst verbindet sich nie mit dem Server.
2. Der Queue-Worker setzt die Aufgabe auf `running` und verbindet sich mit `phpseclib`. Er prüft zuerst den Host-Schlüssel und meldet sich erst danach als `owpanel-agent` an (E-062).
3. Er startet `sudo -n /opt/owpanel/bin/<Skript>` mit einzeln escapten Parametern. Nur Skripte aus der Positivliste `App\Enums\ServerScript` sind möglich; ein Test vergleicht sie mit den `sudo`-Regeln und `server/bin/`.
4. Die Ausgabe landet laufend in `server_tasks.output`. Die Aufgabenansicht (Livewire) fragt sie jede Sekunde ab, bis die Aufgabe beendet ist.
5. Aus Exit-Code und JSON-Ergebnis wird `succeeded` oder `failed` mit Fehlercode. Eine Aufgabe läuft genau einmal (E-063).
6. Mehrere Schritte nacheinander, etwa beim Neuaufbau einer Website (E-106), sind gewöhnliche Aufgaben mit `after_task_id`. Eine solche Aufgabe wartet als `pending`, bis ihre Vorgängerin endet: bei Erfolg kommt sie in die Queue, bei einem Fehler endet sie mit allen folgenden als `failed` mit dem Fehlercode `previous_failed`, ohne zu laufen.

## Ablauf: Weiteren Server einbinden

Geplant nach E-166 und E-167.

1. Der Administrator legt unter Server → „Server hinzufügen“ einen Namen fest. Das Panel erzeugt ein Einmal-Token (15 Minuten, einmal gültig) und zeigt `bash get-owpanel --join <Panel-Adresse>`.
2. Der Nutzer führt den Befehl als root auf einem frischen Debian-13-Server im selben privaten Hetzner-Netz aus. `get-owpanel` fragt das Token ab, statt es als Parameter zu nehmen.
3. Mit dem Token lädt es vom Panel per HTTPS die Serverskripte in der Version des Panels und den öffentlichen SSH-Schlüssel des Panels und prüft das Archiv.
4. Der Installer richtet den Server mit denselben Schritten wie bei einer Installation ein, ohne die Teile des Panels: Hostname, `setup-agent`, `server-provision` mit der Firewall eines Website-Servers, Zeitzone.
5. Er meldet Host-Schlüssel, Hostname, private und öffentliche Adressen mit dem Token zurück.
6. Das Panel prüft SSH über die private Adresse gegen genau diesen Host-Schlüssel, bestätigt die Firewall (E-044) und entwertet das Token.
7. Danach bietet es einen Backup-Subaccount auf derselben Storage Box wie beim Panel-Server an (E-168).

## Ablauf: Website anlegen

Umgesetzt mit E-074.

1. Administrator gibt unter „Websites“ die Domain an, bei mehreren Servern auch den Server, und wählt, ob ein Zertifikat angefordert wird (Standard: ja). Der Typ ist vorerst immer PHP.
2. Panel prüft die Domain nach denselben Regeln wie das Skript, wandelt Umlaut-Domains in Punycode um, lehnt die eigene Domain des Panels und bereits vergebene Domains ab, lost den Linux-Benutzer aus und speichert die Website mit Status `pending`.
3. Ein Queue-Job (Serveraufgabe mit Status und Log) startet zum Beispiel:

   ```text
   sudo /opt/owpanel/bin/site-create --domain example.de --type php --user ow10042 --tls
   ```

4. Das Skript validiert die Eingaben erneut.
5. Es legt den vom Panel vorgegebenen Linux-Benutzer, Verzeichnisse und PHP-FPM-Pool in der gewählten PHP-Version an (`--php`, E-172; ohne Angabe behält eine bestehende Website ihre Version), mit den PHP-Einstellungen der Website (`--php-memory` usw., E-173; ohne Angabe bleiben die gespeicherten). Session-Cookies sind `HttpOnly` und `SameSite=Lax`, `Secure` erst, wenn ein Zertifikat die Domain abdeckt; nach einem neuen Zertifikat schreibt das Skript den Pool deshalb noch einmal (E-176).
6. Es erzeugt die NGINX-Konfiguration und prüft sie mit `nginx -t`.
7. Bei Erfolg lädt es NGINX neu. Bei einem Fehler stellt es die vorherige Fassung wieder her.
8. Mit `--tls` holt es per Certbot ein Zertifikat und stellt die Website auf HTTPS um. Die Einzelheiten stehen in E-033 bis E-035.
9. Panel setzt den Status auf `active` oder `failed` und übernimmt PHP-Version und `tls` aus dem JSON-Ergebnis. Nach einem Fehler startet „Erneut anlegen“ dasselbe Skript mit denselben Parametern.

## Ablauf: Datenbank anlegen

Umgesetzt mit E-078.

1. Administrator gibt auf der Website unter „Datenbanken“ den frei gewählten Teil des Namens an, etwa `shop`; der volle Name ist `ow38217_shop`.
2. Panel erzeugt ein Passwort, speichert es verschlüsselt und die Datenbank mit Status `pending`.
3. Ein Queue-Job startet `db-create --name ow38217_shop` und schreibt das Passwort auf die Standardeingabe.
4. Das Skript prüft Name, Passwort und Website und legt Datenbank und Benutzer für `localhost` und `127.0.0.1` an; ein zweiter Lauf ändert nichts, ein neues Passwort ersetzt das alte.
5. Panel setzt den Status auf `active` oder `failed`. Ein neues Passwort übernimmt das Panel erst nach Erfolg.

## Ablauf: Datenbank exportieren und importieren

Umgesetzt mit E-082. Beides sind Serveraufgaben mit Status und Protokoll.

1. Export: Der Administrator klickt bei der Datenbank unter „Export/Import“ auf „Export erstellen“. Das Panel legt eine Aufgabe `db-export` mit einem zufälligen Dateinamen im Transferverzeichnis an.
2. Der Queue-Worker ruft `db-export --name ow38217_shop` auf und schreibt das Passwort auf die Standardeingabe. Das Skript gibt den mit gzip komprimierten Dump von `mariadb-dump` (als Datenbankbenutzer) auf stdout aus; Protokoll und Ergebniszeile gehen auf stderr. Der Worker schreibt stdout direkt in die Datei.
3. Nach Erfolg lädt die Seite „Export/Import“ neu und bietet die Datei 24 Stunden zum Herunterladen an, ebenso die Aufgabenseite. Bei einem Fehler wird die Datei gelöscht.
4. Import: Der Administrator lädt eine `.sql`- oder `.sql.gz`-Datei hoch (höchstens 256 MB) und bestätigt, dass Daten überschrieben werden können. Das Panel speichert sie im Transferverzeichnis und legt eine Aufgabe `db-import` an, mit `--gzip`, wenn die Datei mit gzip beginnt.
5. Der Worker schickt Passwort und Datei in Stücken von 1 MB auf die Standardeingabe von `db-import`. Das Skript entfernt DEFINER-Angaben und spielt den Dump mit `mariadb` als Datenbankbenutzer ein; es stoppt beim ersten Fehler und schreibt die Meldung von MariaDB ins Protokoll.
6. Nach der Aufgabe wird die hochgeladene Datei gelöscht. Der Scheduler löscht stündlich alle Dateien, die älter als 24 Stunden sind.

## Ablauf: Datenbank-Werkzeug

Umgesetzt mit E-079. Keine Serveraufgabe; der Benutzer wartet auf die Antwort.

1. Administrator öffnet bei einer Datenbank „Tabellen“, eine Tabelle oder „SQL“.
2. Panel prüft über die `DatabasePolicy`, ob er diese Datenbank nutzen darf, und baut das SQL: für Tabellen eine Abfrage auf `information_schema`, für eine Tabelle Spalten, Anzahl und eine Seite mit 50 Zeilen in einem Aufruf.
3. Die Webanfrage legt einen verschlüsselten Job in die Queue `queries` und wartet. Der Abfrage-Worker `owpanel-query` ruft `db-query --name ow38217_shop --max-rows 1000` über SSH auf und schreibt Passwort und SQL auf die Standardeingabe. Die Webanfrage selbst verbindet sich nie mit einem Server, weil sie den SSH-Schlüssel nicht lesen kann.
4. `db-query` prüft den Namen und startet als `ow38217` mit einer Zeitgrenze von 30 Sekunden `lib/db-query.php`. Der Helfer meldet sich mit PDO als `ow38217_shop` an, setzt `max_statement_time` auf 25 Sekunden und führt die Befehle aus.
5. Die Ergebnisse kommen als eine JSON-Zeile zurück: je Befehl Spalten, Zeilen und Gesamtzahl oder die Zahl der betroffenen Zeilen, dazu ein SQL-Fehler, falls einer den Lauf beendet hat.
6. Der Worker legt die Antwort verschlüsselt für höchstens zwei Minuten in den Cache; die Webanfrage holt sie ab, löscht sie dabei, prüft ihre Form und zeigt sie an. Dauerhaft gespeichert wird nichts.

## Ablauf: Website löschen

1. Administrator öffnet die Bestätigungsseite und gibt die Domain ein (E-038), solange keine Aufgabe der Website läuft. Wahlweise kreuzt er an, dass die vom Panel gesetzten DNS-Einträge mit gelöscht werden (`sites.delete_dns_records`, E-116).
2. Panel setzt den Status auf `deleting` und startet `site-delete --user <benutzer>` als Serveraufgabe.
3. Bei Erfolg entfernt das Panel, falls gewählt, zuerst die eigenen DNS-Einträge, die noch genau seinen Wert enthalten, und danach die Website aus seiner Datenbank. Aufgabe und Log bleiben erhalten, das Audit-Log behält Domain und Benutzer. Bei einem Fehler wird der Status `failed`, und das Löschen lässt sich wiederholen.

## Ablauf: DNS einer Website

Umgesetzt mit E-116 und E-118, nur für Administratoren.

1. Die Seite „DNS“ vergleicht A und AAAA für die Domain und `www` mit den Adressen des Servers: Zone und RRSets über die Hetzner Cloud API (DNS-Token oder Projekt-Token), Antworten der zuständigen Nameserver per UDP ohne Rekursion (`App\Support\DnsQuery`) und den Resolver des Panel-Servers (fünf Minuten Cache).
2. Anlegen und Korrigieren laufen über eine Bestätigungsseite; das Panel setzt das RRSet auf genau die Server-Adresse und wartet auf die Aktion bei Hetzner. Gesetzte Einträge stehen in `site_dns_records`.
3. Beim Anlegen einer Website legt die Option „DNS-Einträge bei Hetzner anlegen“ fehlende Einträge vor `site-create` an, damit das Zertifikat gleich klappt.

## Ablauf: SSH eines Servers

Umgesetzt mit E-155. Server → SSH liest den Zustand mit `server-ssh --status` über den Abfrage-Worker (`ReadSsh`, wie `ReadFail2ban`) und schaltet die Passwort-Anmeldung als Serveraufgabe mit `server-ssh --password-login off|on`. Dabei steuert nur die Datei `00-owpanel-password.conf` in `sshd_config.d`; die SFTP-Regeln in `owpanel-sftp.conf` bleiben unberührt. Die SFTP-Seite fragt für Administratoren denselben Zustand ab und warnt, wenn `AllowUsers`/`AllowGroups` die Gruppe `owpanel-sftp` ausschließen.

## Ablauf: DNS eines Servers

Umgesetzt mit E-153, nur für Administratoren. Server → DNS prüft A und AAAA des Hostnamens wie bei einer Website (`SiteDns::checkHosts`) und setzt fehlende oder falsche Einträge in einer Hetzner-Zone (`SiteDnsWriter::setRecords`, Audit `server.dns_record_set`). Reverse DNS (PTR) der IPv4- und der IPv6-Adresse des Servers kommt bei Hetzner-Servern über `GET /servers/{id}` und wird über `POST /servers/{id}/actions/change_dns_ptr` gesetzt oder mit leerem Feld zurückgesetzt (Audit `server.dns_ptr_set`); die Server-ID sucht das Panel bei Bedarf über die Adressen. Andere Server zeigen den PTR aus dem DNS nur an. Das Panel prüft, ob der PTR-Name zurück auf die Adresse zeigt.

## Linux-Benutzer

Jede Website bekommt einen Linux-Benutzer `ow` mit einer zufälligen Nummer zwischen 10001 und 59999, zum Beispiel `ow38217`. `ow10000` ist für das Panel selbst reserviert (E-041). Die Zahl ist zugleich UID und GID (E-034). Das Panel lost die Nummer aus und prüft in seiner Datenbank, dass sie auf keinem Server vergeben ist. Der Name ist danach unveränderlich und auch der SFTP-Benutzername.

- Die Zuordnung zu Kunde und Domain steht nur in der Panel-Datenbank, nicht im Namen.
- Kundennamen erscheinen dadurch nicht in `/etc/passwd`, die jeder Benutzer auf dem Server lesen kann.
- Der Name ist über alle Server eindeutig, sodass ein Umzug keine Konflikte erzeugt.
- Aus dem eigenen Benutzernamen lässt sich nicht ablesen, wie viele Websites es gibt. Wer Code auf einem Server ausführt, kann `/etc/passwd` aber weiterhin lesen und die Konten dieses Servers zählen.
- Das Panel zeigt SFTP-Benutzer, Server und Port bei der Website an.

### SFTP

Der SFTP-Zugang einer Website nutzt ihren Linux-Benutzer (E-089). Die Grundeinrichtung legt die Gruppe `owpanel-sftp` und einen Drop-in für sshd an, der nur für diese Gruppe gilt:

```
Match Group owpanel-sftp
    ChrootDirectory /var/www/%u
    ForceCommand internal-sftp -d /htdocs -u 0027
    AuthorizedKeysFile /etc/ssh/owpanel-sftp/%u
    AuthenticationMethods publickey password
```

Dazu kommen `PermitTTY no` und das Abschalten aller Weiterleitungen. Das Website-Verzeichnis gehört root mit 0750, wie sshd es für ein Chroot verlangt; schreiben kann der Benutzer erst in `htdocs`. `site-sftp` nimmt den Benutzer in die Gruppe auf, setzt oder sperrt sein Passwort und schreibt seine Schlüssel. Ohne Gruppe gibt es keine Anmeldung, weil die Shell der Website-Benutzer `/usr/sbin/nologin` ist.

## Rollen und Kundenbereich

| Rolle | Darf |
|---|---|
| `admin` | Alles, einschließlich Server, Firewall, Kunden, Pakete, API-Tokens und Einstellungen |
| `customer` | Nur eigene Websites, Datenbanken, Cronjobs, Backups, Logs und SFTP-Zugänge, im Rahmen des Pakets (E-124, E-125) |

Ein Kunde ist ein Eintrag in `customers`. Zu jedem Kunden gehört höchstens ein Benutzerkonto, das der Administrator mit „Zugang anlegen“ einrichtet (E-128), und beliebig viele Websites. Eine Website gehört einem Kunden, der Administration (`admin_owned`, etwa die eigene Homepage) oder ist nicht zugeordnet (E-139); nur Websites eines Kunden mit Paket haben Grenzen.

Der Kundenbereich nutzt dieselbe Anwendung mit eigenen Routen und eigener Navigation („Meine Webseiten“ im Hauptmenü, „Mein Konto“ im Menü oben rechts, E-124). Server, Firewall, Aufgaben-Logs und Panel-Einstellungen sind für Kunden nicht sichtbar. Die Zugehörigkeit prüfen die Policies über `customer_id` (`SitePolicy::owns`, `DatabasePolicy`); zusätzlich trennt die Route-Middleware `role` die Seiten der Verwaltung (`role:admin`) vom Kundenbereich unter `/my` (`role:customer`, E-143). Kunden können sich mit Passwort oder Pocket ID anmelden (E-138).

SFTP, Logs, Datenbanken, Cronjobs und Backups nutzen in beiden Bereichen dieselben Controller und Ansichten. Die Routen des Kundenbereichs heißen `my.…`; `App\Support\Area` erkennt daran den Bereich, baut die Links und schickt Kunden nach einer Aktion auf ihre Seite statt ins Aufgaben-Log (E-143 bis E-145). Eigene Seiten hat der Kundenbereich nur für die Übersicht einer Website und die DNS-Prüfung.

Paketgrenzen prüft `App\Customers\PackageLimits` zentral in den Aktionen (E-139). Kunden werden abgewiesen und sehen ausgeschöpfte Grenzen vorher an deaktivierten Formularen mit Begründung (E-146); Administratoren dürfen nach einer Bestätigung über die Grenzen gehen, das steht im Audit-Log.

### Pakete und Speicherplatz

Grundlage sind E-125, E-126 und E-149.

- Ein Paket gilt für einen Kunden und nennt Websites, Speicherplatz (Dateien und Datenbanken zusammen), Datenbanken, Cronjobs, den kürzesten Abstand der Cronjobs und selbst gestartete Sicherungen und Wiederherstellungen je Tag. Ohne Paket kann der Kunde nichts anlegen.
- `site-usage` misst alle 15 Minuten je Server die Dateien jedes Website-Benutzers (aus der Quota) und die Größe seiner Datenbanken.
- Das Panel rechnet je Kunde: frei = Speicherplatz − alle Dateien − alle Datenbanken. Jede Website bekommt mit `site-quota` die harte Grenze „eigene Dateien + frei“, nie weniger als die aktuelle Belegung.
- Ist nichts mehr frei, können Dateien nicht mehr wachsen, neue Datenbanken und Importe lehnt das Panel ab. Eine Wiederherstellung hebt die Quota für ihre Dauer auf.
- Die Grundeinrichtung schaltet Benutzerquotas für das Dateisystem mit `/var/www` ein (ext4): `/etc/fstab` sichern, Optionen ergänzen, mit `findmnt --verify` prüfen, ohne Neustart aktivieren, bei Fehlern zurücknehmen. Auf anderen Dateisystemen misst `site-usage` mit `du` und meldet `"quota":false`; das Panel zeigt dann „keine Quotas aktiv“.
- Gemessen wird ohne Aufgabe in der Liste: `owpanel:storage` startet alle 15 Minuten je Server den Job `MeasureStorage`. Er speichert `sites.usage_files_bytes`, `usage_db_bytes`, `usage_measured_at` und `servers.quota_active`, `storage_filesystem` und ruft `site-quota` nur auf, wenn sich die Grenze um mindestens 1 MiB ändert und für die Website keine Aufgabe läuft (`sites.quota_kib` hält die gesetzte Grenze). Rechnung in `App\Customers\StorageQuota`.
- Anzeige: Balken `<x-storage-bar>` unter „Meine Webseiten“ und Kunde → Paket, Spalte „Belegt“ in den Listen, Belegung in der Übersicht jeder Website. `AlertChecker` meldet `storage_full` je Kunde mit Entwarnung.

## Externe Systeme: API und Webhooks

Abrechnung und Kundenstammdaten liegen in einem externen System, bei goettlichIT zum Beispiel in der eigenen Homepage. Diese Homepage kann selbst als normale Website auf OWPanel laufen.

```text
Externes System (Kunden, Verträge, Rechnungen)
      │  API mit Token          ▲  Webhooks
      ▼                         │
OWPanel (Konten, Websites, Datenbanken, Backups)
```

Erste Stufe (E-165, Meilenstein 7.1): Die API dient nur der eigenen Homepage. Sie liest Kunden, Pakete sowie Websites mit Status und Verbrauch und setzt oder entfernt einen Zahlungshinweis je Kunde. Websites legt nur das Panel an, gesperrt wird von Hand, Webhooks gibt es zunächst nicht, und die API nennt keine Server. Die folgenden Abläufe beschreiben das spätere Ziel.

Aufgabenteilung:

- **OWPanel ist führend** für den technischen Zustand: welche Leistungen existieren und in welchem Status.
- **Das externe System ist führend** für Stammdaten, Verträge und Rechnungen. Es importiert die gebuchten Leistungen aus OWPanel und hält eine Kopie für die Abrechnung.
- **Der Kunde verwaltet im Panel** (E-130): Die Homepage zeigt seine Websites mit Status, Paket und Verbrauch über ein Token mit Leserechten und verweist mit „Verwalten“ in den Kundenbereich. Beide nutzen dieselbe Pocket-ID-Instanz, sodass der Kunde nur eine Anmeldung braucht.
- Die Verknüpfung erfolgt über die externe Kundennummer in `customers.external_id`. Das Panel vergibt zusätzlich eine eigene, unveränderliche Nummer (`customers.number`, angezeigt als K10001); die API liefert sie zurück, damit das externe System beide kennt (E-085).

Typische Abläufe:

| Ereignis im externen System | Aufruf an OWPanel |
|---|---|
| Kunde bucht Webspace | Kunde und Website anlegen |
| Rechnung nicht bezahlt | Website sperren |
| Zahlung eingegangen | Website entsperren |
| Vertrag beendet | Letztes Backup, danach Website löschen |
| Abrechnung erstellen | Leistungen und Verbrauch abrufen |

Regeln für die Schnittstelle:

- REST-API unter `/api/v1`, Antworten als JSON
- Anlegende Aufrufe akzeptieren einen Idempotenzschlüssel, damit ein wiederholter Aufruf keine doppelte Website erzeugt
- Lange Aktionen liefern sofort eine Aufgaben-ID und laufen als Queue-Job
- Webhooks melden Ereignisse wie `site.created`, `site.suspended` oder `certificate.expiring`
- Da Webhooks verloren gehen können, ruft das externe System den Stand zusätzlich regelmäßig ab
- Websites ohne Kundenzuordnung sind für API-Tokens nicht erreichbar, damit sich zum Beispiel die Homepage nicht selbst sperren kann

Fällt das Panel aus, laufen die Websites weiter, weil NGINX, PHP-FPM und MariaDB unabhängig vom Panel arbeiten.

## Paketquellen

Grundlage ist E-042. Die Grundeinrichtung bindet zwei Paketquellen zusätzlich zu Debian 13 ein:

| Quelle | Inhalt | Schlüssel-Fingerabdruck |
|---|---|---|
| `https://nginx.org/packages/debian` | NGINX, stabile Version | `573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62`, `8540A6F18833A80E9C1653A42FD21310B49F6B46`, `9E9BE90EACBCDE69FE9B204CBCDCD8A38D88A2B3` |
| `https://packages.sury.org/php` | PHP 8.5 und 8.6 mit Erweiterungen (E-172) | `15058500A0235D97F5D10063B188E2B695BD4743` |

- Die Schlüssel werden bei jedem Lauf von `server-provision` neu geladen. Das Skript übernimmt nur Schlüssel mit einem der festgelegten Fingerabdrücke und lässt abgelaufene weg. nginx.org signiert mit mehreren Schlüsseln, Debian 13 derzeit mit `8540A6F1…`. Es legt ihn unter `/etc/apt/keyrings/owpanel-*.gpg` ab.
- Die Quellen stehen als `/etc/apt/sources.list.d/owpanel-*.sources` mit `Signed-By` auf genau diesen Schlüssel.
- `/etc/apt/preferences.d/owpanel-nginx` gibt Paketen von nginx.org Vorrang vor Debians NGINX.
- MariaDB, Certbot und Fail2ban kommen weiter aus Debian.
- `unattended-upgrades` spielt täglich die Sicherheitsupdates von Debian und alle Updates der beiden zusätzlichen Quellen ein (E-047). `server-provision` selbst aktualisiert keine Pakete.

Die Pakete von nginx.org sind anders aufgebaut als Debians: NGINX läuft als `nginx`, und es gibt weder `sites-enabled` noch `snippets/`. OWPanel ergänzt deshalb eigene Dateien unter `/etc/nginx/conf.d/` und `/etc/nginx/owpanel/` und ersetzt die Begrüßungsseite `conf.d/default.conf`.

## HTTP/3 und QUIC

Grundlage sind die Entscheidungen E-025 bis E-027.

HTTP/3 läuft über QUIC und damit über **UDP** Port 443, nicht über TCP. HTTP/2 und HTTP/1.1 bleiben über TCP Port 443 erhalten. Browser erfahren über den Header `Alt-Svc`, dass HTTP/3 verfügbar ist, und wechseln bei der nächsten Anfrage. Ist UDP irgendwo blockiert, nutzen sie automatisch weiter TCP.

NGINX stammt aus der stabilen Paketquelle von nginx.org (E-042) und enthält das Modul `ngx_http_v3_module`. Die Grundeinrichtung prüft das mit `nginx -V` und bricht mit einer klaren Meldung ab, falls das Modul fehlt. Das Modul gilt bei NGINX noch als experimentell.

### NGINX: Standardserver

Die Grundeinrichtung legt einen Standardserver an, der unbekannte Domains abweist. Nur er setzt `reuseport`, weil NGINX diese Option je Adresse und Port nur einmal erlaubt:

```nginx
server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    listen 443 quic reuseport default_server;
    listen [::]:443 quic reuseport default_server;

    http2 on;
    http3 on;
    ssl_reject_handshake on;
}
```

Für den Standardserver gibt es kein Zertifikat. `ssl_reject_handshake` lehnt Verbindungen zu unbekannten Domains ab, ohne ein fremdes Zertifikat auszuliefern.

### NGINX: Website mit Zertifikat

```nginx
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    listen 443 quic;
    listen [::]:443 quic;

    http2 on;
    http3 on;
    server_name example.de;

    ssl_certificate     /etc/letsencrypt/live/ow38217/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ow38217/privkey.pem;

    add_header Alt-Svc 'h3=":443"; ma=86400' always;
}
```

Regeln für die Vorlagen:

- Eine neue Website bekommt zuerst nur Port 80 für die Let's-Encrypt-Prüfung. Erst wenn das Zertifikat da ist, erzeugt das Skript die Konfiguration mit TLS und QUIC.
- `add_header` wird in NGINX nicht vererbt, sobald ein `location`-Block eigene Header setzt. Die Vorlagen binden die Sicherheits- und `Alt-Svc`-Header deshalb als gemeinsames Snippet `/etc/nginx/owpanel/headers.conf` in jeden solchen Block ein. Welche Header das sind, steht in E-045.
- Reverse-Proxy-Websites sprechen nach außen HTTP/3 und zur Anwendung weiter HTTP/1.1.
- Das Panel selbst nutzt dieselben Vorlagen und ist damit ebenfalls über HTTP/3 erreichbar.
- Die www-Adresse einer Website (E-110) kommt aus `site-www-http.conf` (Port 80 mit ACME-Pfad, Weiterleitung) und, wenn das Zertifikat sie abdeckt, `site-www-https.conf` (HTTPS und HTTP/3, Weiterleitung). Beide hängt `owp_site_nginx` an die Datei der Website an.
- Beim Wechsel der Domain (E-112) hängt `site-create --previous-domain` an die bisherige Datei vorübergehend `site-acme-http.conf` (Port 80, nur ACME-Pfad, sonst 404) für die neuen Namen an, fordert das Zertifikat an und schreibt erst danach die Datei für die neue Domain. Lehnt Let's Encrypt ab, kommt die bisherige Datei zurück.

### NGINX: Globale Einstellungen

```nginx
quic_host_key /etc/owpanel/quic_host.key;
ssl_early_data off;
```

`quic_host_key` ist ein zufälliger Schlüssel, den die Grundeinrichtung einmal erzeugt. Ohne ihn erzeugt NGINX bei jedem Reload einen neuen, und laufende QUIC-Verbindungen können nicht sauber fortgesetzt werden.

### Systemeinstellungen

QUIC profitiert von größeren UDP-Puffern. Die Grundeinrichtung legt `/etc/sysctl.d/60-owpanel-quic.conf` an:

```text
net.core.rmem_max = 7500000
net.core.wmem_max = 7500000
```

### Firewall

Die Firewall nutzt nftables, das unter Debian 13 Standard ist (E-026, E-044). Das Panel verwaltet eine eigene Tabelle `inet owpanel`. Andere Tabellen, etwa die von Fail2ban, bleiben unberührt. Eingehender Verkehr ist standardmäßig gesperrt, freigegeben sind:

| Protokoll | Port | Zweck |
|---|---|---|
| TCP | 22 oder der eingestellte SSH-Port | SSH und Panel-Steuerung |
| TCP | 80 | HTTP und Let's-Encrypt-Prüfung |
| TCP | 443 | HTTPS mit HTTP/1.1 und HTTP/2 |
| UDP | 443 | HTTP/3 über QUIC |

Dazu kommen Antworten auf bestehende Verbindungen, das Loopback-Interface, die für IPv6 nötigen ICMPv6-Nachrichten, ICMP-Fehlermeldungen für die Pfad-MTU und DHCP-Antworten.

Ablauf bei jeder Änderung:

1. Das Skript erzeugt die neue Regeldatei aus `templates/nftables/owpanel.nft` und prüft sie mit `nft -c -f`.
2. Es sichert die laufende Tabelle unter `/run/owpanel/firewall/`, startet einen systemd-Zeitgeber über 60 Sekunden und lädt die neuen Regeln in einem Schritt.
3. `server-provision` meldet `"firewall_confirm_required":true`. Das Panel baut eine neue SSH-Verbindung auf und ruft `sudo /opt/owpanel/bin/firewall-confirm` auf.
4. Die Bestätigung stoppt den Zeitgeber und speichert die Regeln in `/etc/owpanel/firewall.nft`. Beim Neustart lädt sie der Dienst `owpanel-firewall.service`.
5. Kommt keine Bestätigung, stellt der Zeitgeber die gesicherte Tabelle wieder her. Gab es vorher keine, entfernt er die neue Tabelle wieder.

**Eigene Regeln (E-090):** Unter „Server → Firewall“ lassen sich weitere Ports öffnen, für alle oder nur für eine Adresse oder ein Netz. Das Skript `firewall-rules` bekommt die ganze Liste über stdin, fügt sie nach den festen Freigaben in die Vorlage ein und durchläuft die Schritte oben. Das Panel ruft `firewall-confirm` direkt im selben Job auf. Die bestätigte Liste liegt in `/etc/owpanel/firewall-rules.list`, damit auch ein neuer Lauf der Grundeinrichtung sie behält. Sperren lassen sich über die Oberfläche nicht als Regeln anlegen, nur Freigaben.

**Sperrlisten (E-170):** Unter „Server → Firewall“ trägt der Administrator Adressen von Sperrlisten ein. Das Panel lädt und prüft sie (`App\Firewall\BlocklistFetcher`, `BlocklistParser`), legt die Einträge in der Tabelle `blocklist_contents` ab und holt fällige Listen stündlich mit `owpanel:blocklists`. Ändert sich etwas, überträgt eine Aufgabe alle Listen des Servers mit `server-blocklist` über stdin (`block<TAB>Netz`, `allow<TAB>Netz`). Das Skript lädt sie in die eigene Tabelle `inet owpanel_block`: Sets `allow4`, `allow6`, `block4`, `block6` und eine Kette mit Priorität `filter - 10`, die nur verwirft. Ausnahmen sind die fail2ban-Ausnahmen und die Adressen des Panel-Servers. Abgewiesene Adressen zählt die Kette in den dynamischen Sets `hits4` und `hits6` (7 Tage ohne Treffer, dann fallen sie heraus); das Laden tauscht nur Listen und Regeln aus, die Zähler bleiben. `server-blocklist --stats` liest sie, `ReadBlocklistHits` holt sie beim Öffnen der Firewall-Seite über den Query-Worker (fehlt die Tabelle oder zählt sie noch nicht, überträgt die Seite die Listen höchstens alle zehn Minuten neu), und `App\Firewall\BlocklistIndex` ordnet jede Adresse ihren Listen zu; derselbe Index prüft die eigenen Adressen und das Feld „Adresse prüfen“ (`?check=`, nur lesend). Gespeichert wird in `/etc/owpanel/blocklist.nft`; `owpanel-blocklist.service` lädt die Datei beim Start nach `owpanel-firewall.service`. Die Tabelle gehört nicht zur Rücknahme nach 60 Sekunden.

**fail2ban (E-090, E-174):** Neben `sshd` überwacht der Jail `owpanel-hidden` die Datei `/var/log/nginx/owpanel-hidden.log`, in die NGINX alle abgelehnten Abrufe versteckter Dateien schreibt, und sperrt Scanner für die Webports. Die Ausnahmen schreibt `server-fail2ban --action ignore` als `/etc/fail2ban/jail.d/owpanel-ignore.local` (`ignoreip`), getrennt von der Datei der Grundeinrichtung. `--action status` liefert die gesperrten Adressen aller Jails mit Ende der Sperre, `--action unban --ip` hebt eine Sperre auf.

Die Grundeinrichtung richtet die Firewall als letzten Schritt ein, damit die 60 Sekunden nicht für Paketinstallationen verbraucht werden. Ist `ufw` aktiv, bricht sie ab, weil zwei Werkzeuge sich gegenseitig stören würden. Fail2ban sperrt über seine eigene nftables-Tabelle.

Startet jemand Debians Dienst `nftables.service` neu, leert dessen `/etc/nftables.conf` mit `flush ruleset` auch die Tabelle des Panels. `systemctl restart owpanel-firewall` lädt sie wieder.

**Externe Firewalls:** Eine Firewall beim Anbieter, etwa die Hetzner Cloud Firewall, kann das Panel nicht ändern. Dort muss UDP 443 zusätzlich freigegeben werden. Das Panel weist bei der Einrichtung darauf hin und prüft nach dem Start von außen, ob HTTP/3 erreichbar ist.

## Besucherstatistik

Grundlage ist E-148. Einmal in der Stunde startet `owpanel:site-stats` je Server einen Job (`CollectSiteStats`), der für jede aktive Website `site-stats --user <owNNNNN> --days 2` aufruft, beim ersten Mal `--days 14`. Das Skript liest das Access-Log und die rotierten Logs, zählt je Tag Besucher (Adresse und Browser, gehasht nur während des Laufs), Seitenaufrufe und die zehn meistbesuchten Pfade und gibt nur diese Zahlen aus. Das Panel ersetzt damit die Zeilen in `site_visits` (eine je Website und Tag) und löscht Tage nach 14 Monaten. System → Statistik zeigt die Zahlen; es ist keine Aufgabe, weil sich auf dem Server nichts ändert.

## Website sperren

Grundlage sind E-127 und E-151.

Eine gesperrte Website behält alle Daten. Sperren kann ein Administrator mit interner Notiz und später die API; die API hebt nur ihre eigenen Sperren auf.

1. `site-suspend` ersetzt die NGINX-Konfiguration der Website durch eine Sperrseite mit HTTP 503 für Domain und www. Der Pfad für Let's Encrypt bleibt erreichbar. Die neue Konfiguration wird mit `nginx -t` geprüft, sonst gilt die alte weiter.
2. Der PHP-FPM-Pool ruht, SFTP ist gesperrt und offene Sitzungen werden getrennt, die Crontab wird beiseitegelegt. Den vorherigen Zustand merkt sich das Skript auf dem Server.
3. `site-unsuspend` stellt genau diesen Zustand wieder her. Beide Skripte sind wiederholbar.
4. Das Panel speichert die Sperre in `sites.suspended_at`, `suspended_by` und `suspend_note`. Während der Sperre sind Änderungen an der Website gesperrt; der Kunde sieht „Gesperrt“ ohne Grund.
5. Wahlweise sperrt der Administrator auch den Zugang des Kunden (`users.locked_at`). Dann sind Anmeldungen abgelehnt und offene Sitzungen beendet.

Umsetzung (E-151): `site-suspend` fügt nach jeder `server_name`-Zeile `include /etc/nginx/owpanel/site-suspended.conf` ein, legt Pool und `/etc/cron.d/owpanel-<Benutzer>` unter `/var/lib/owpanel/suspended/<Benutzer>/` ab und lässt das Konto ablaufen (`usermod --expiredate 1`), damit SFTP mit Passwort und Schlüssel scheitert. `site-unsuspend` macht alles rückgängig. Im Panel prüft die Middleware `GuardSuspendedSites` jede ändernde Anfrage zu einer Website oder ihren Datenbanken; Kunden dürfen nur lesen, Administratoren entsperren, löschen, sichern, wiederherstellen und exportieren. Seiten einer gesperrten Website zeigen einen Hinweis und deaktivierte Formulare. `Rebuild` hängt an den Neuaufbau einer gesperrten Website `site-suspend` an.

## Anmeldung und Pocket ID

Es gibt zwei gleichwertige Anmeldewege:

| Weg | Ablauf |
|---|---|
| Passwort | E-Mail und Passwort aus der Panel-Datenbank, danach Zwei-Faktor, falls aktiv |
| Pocket ID | Schaltfläche auf der Anmeldeseite, Passkey bei Pocket ID, keine weitere Panel-Abfrage |

Das Benutzerkonto liegt immer in der Panel-Datenbank, auch bei einer Anmeldung über Pocket ID. Dort stehen Rolle, Sprache und Rechte. Ist Pocket ID nicht eingerichtet, zeigt die Anmeldeseite nur den Passwort-Weg.

Pocket ID wird über OpenID Connect (OIDC) angebunden, einen Standard für die Anmeldung über einen externen Dienst. Es läuft getrennt vom Panel und wird in dieser Version nicht vom Panel installiert.

1. Ein Administrator trägt unter System → Pocket ID den Namen der Instanz, ihre Adresse, Client-ID und Client-Secret ein. Beim Speichern prüft das Panel Discovery und Schlüssel (E-113).
2. Das Panel zeigt die Callback-URL `/auth/oidc/callback` an, die in Pocket ID eingetragen wird.
3. Administratoren dürfen Pocket ID immer nutzen, Kundenkonten erst nach Freischaltung unter System → Benutzer (`users.oidc_allowed`, E-114).
4. Ein angemeldeter Benutzer verknüpft sein Konto einmalig unter Mein Konto → Anmeldung und Sicherheit.
5. Auf der Anmeldeseite steht danach „Anmelden mit <Name>“. Das Panel identifiziert den Benutzer über Aussteller und `sub`-Wert in `oidc_identities`, nicht über die E-Mail-Adresse, und markiert die Sitzung mit `auth.via=oidc`.
6. Das Passwort des Kontos bleibt erhalten; Passwort, Passwort mit Zwei-Faktor und Pocket ID bestehen nebeneinander (E-114). Einen Schalter zum Abschalten der Passwort-Anmeldung gibt es nicht.

Umgesetzt in `app/Oidc/` (Client und Einstellungen) und `OidcController` (Start, Callback, Verknüpfen, Trennen). Ein Anmeldeversuch muss binnen 10 Minuten zurückkommen; `state`, `nonce` und PKCE-Verifier liegen nur in der Sitzung und gelten einmal.

Fällt Pocket ID aus, bleibt der lokale Notfallzugang nutzbar. Die Regeln dafür stehen in [SICHERHEIT.md](SICHERHEIT.md).

## Veröffentlichung auf GitHub

- Das Repository enthält weder Geheimnisse noch umgebungsspezifische Werte. Beides entsteht erst bei der Installation.
- Installation und Updates laden versionierte Releases, nicht den aktuellen Stand des Hauptzweigs.
- Jedes Archiv wird in GitHub Actions in einem eigenen Job signiert, der keinen Code aus dem Bau ausführt (`ssh-keygen -Y sign`, Namensraum `owpanel-release`). Den öffentlichen Schlüssel enthalten `release-signers` im Repository und `get-owpanel`; der Installer legt ihn unter `/etc/owpanel/release-signers` ab. `setup-agent` schreibt die Version des Archivs nach `/opt/owpanel/VERSION`, `server-status` meldet sie als `scripts_version` und die Fingerabdrücke der hinterlegten Release-Schlüssel als `release_keys`. Der Installer legt `release-signers` auch neben den Code des Panels (`releases/<Version>/release-signers`); Server → Zustand vergleicht damit und zeigt einen grünen Haken oder eine Warnung (E-167).
- Ein Release entsteht über den Workflow „Tag release“ (E-190): GitHub → Actions → „Tag release“ → „Run workflow“ auf `main` mit der Version. Er prüft Versionsnummer, CHANGELOG-Abschnitt und grüne Workflows auf `main`, setzt den annotierten Tag mit der Noreply-Adresse dessen, der ihn startet, und ruft den Release-Workflow auf. Der Text des GitHub-Releases ist der CHANGELOG-Abschnitt der Version (`scripts/release-notes`); nach einer Korrektur des CHANGELOG ersetzt der Workflow „Release notes“ den Text eines veröffentlichten Releases. Ein von Hand gepushter Tag startet den Release-Workflow weiterhin selbst.
- Das Panel aktualisiert sich mit `panel-update --version x.y.z` (E-185): Das Skript lädt das Release von GitHub, prüft Prüfsumme und Signatur gegen `/etc/owpanel/release-signers` und startet den Installer gelöst als Unit `owpanel-update`. Die Unit hält den Zeitplaner an, wartet höchstens 10 Minuten auf laufende Aufgaben, stoppt den Worker und ruft `install --no-reboot --skip-dns-check` auf; der Installer startet beide wieder. Archiv und Signatur bleiben unter `releases/<Version>/` für die weiteren Server. `panel-update --status` liest den Zustand aus `/var/lib/owpanel/update/status`. Im Panel startet `PanelUpdate::start()` die Aufgabe und merkt sich den Zustand in der Einstellung `panel_update`; `owpanel:panel-update` folgt ihm jede Minute (fertig, wenn `panel-update --status` das Ende meldet und `config('owpanel.version')` die neue Version ist; danach liest es die neueste Version neu) und startet mit `--auto` um 4:47 Uhr das automatische Update für Patch-Versionen, wenn es eingeschaltet ist.
- Weitere Server bekommen neue Skripte mit `server-scripts-update --version x.y.z` (E-187): Über stdin kommen Signatur und Archiv, der Server prüft die Signatur, entpackt nur `server/` und `VERSION` und installiert sie mit `setup-agent --keep-key`. Testbauten (`unsigned`) nur, wo `/etc/owpanel/allow-unsigned` liegt. Das Panel stößt das selbst an (E-188): `ScriptsUpdater` nach jeder Statusprüfung, höchstens alle 15 Minuten je Server; das Archiv kommt aus `releases/<Version>/` (`ScriptsArchive`), fehlt es, lädt `panel-update --fetch-archive` es vorher. Bis dahin lässt `RunServerTask` auf dem Server nur Aufgaben zu, für die `ServerScript::runsWhenOutdated()` gilt.
- Ein Release ist ein fertiges Archiv mit `vendor/` und `public/build/`. Auf den Servern laufen weder Composer noch Node.js (E-048, E-068).
- Jede Installation erzeugt eigene Schlüssel und Passwörter.

## Schnittstelle der Serverskripte

Vorläufiger Vertrag für alle Skripte in `server/bin/`:

- Eingaben nur als benannte Parameter
- Erwartete Meldungen auf stdout, Fehler auf stderr
- Exit-Code `0` bei Erfolg, ungleich `0` bei Fehlern
- Mehrfaches Ausführen führt zum selben Ergebnis
- Letzte Ausgabezeile als JSON-Ergebnis, damit das Panel nichts aus Freitext herauslesen muss
- Das Panel sucht die Ergebniszeile von hinten, weil über SSH Meldungen auf stderr nach ihr ankommen können (E-064)
- Fehlercodes sind feste Wörter in `snake_case`, das Panel übersetzt sie in `lang/*/tasks.php`
- `server-status` erweitert seine Ausgabe nur und baut sie nie um, damit ein Panel einen Server mit älteren oder neueren Skripten weiter lesen kann (E-167)

## Datenmodell

Erster Entwurf:

| Tabelle | Zweck |
|---|---|
| `users` | Panel-Benutzer, Rolle, Kunde, Sprache, Zwei-Faktor-Daten, Sperre des Zugangs (`locked_at`, E-127), Einladung als SHA-256 mit Ablauf (`invitation_token`, `invitation_expires_at`, E-128) |
| `customers` | Panel-Kundennummer, Vor- und Nachname, Firma, E-Mail, Sprache, externe Kundennummer, Paket (E-125) |
| `packages` | Name und Grenzen: Websites, Speicherplatz, Datenbanken, Cronjobs, kürzester Cron-Abstand, Sicherungen und Wiederherstellungen je Tag (E-125) |
| `oidc_identities` | Verknüpfung von Benutzern mit Aussteller und `sub` |
| `server_snapshots` | Läufe der Hetzner-Snapshots je Server: Auslöser, Status, Image- und Action-ID, Fehler, gelöschte alte Snapshots (E-095) |
| `settings` | Panel-Adresse, Pocket-ID-Konfiguration |
| `servers` | Name, Adresse, Host-Schlüssel, Status |
| `sites` | Server, Kunde, Domain (ASCII, Punycode), Typ, Linux-Benutzer, PHP-Version, Zertifikat, Status (`pending`, `active`, `failed`, `deleting`), Sperre (`suspended_at`, `suspended_by`, `suspend_note`, E-127), gemessener Verbrauch (`usage_files_bytes`, `usage_db_bytes`, `usage_measured_at`, E-126) |
| `databases` | Datenbanken einer Website mit gleichnamigem Benutzer, Passwort verschlüsselt, Status (E-078) |
| `certificates` | Zertifikate und Ablaufdaten |
| `cron_jobs` | Cronjobs je Website |
| `settings` | Einstellungen aus der Oberfläche als Schlüssel und Wert, etwa Impressum und Datenschutz (E-091) |
| `firewall_rules` | Eigene Firewall-Regeln je Server: Protokoll, Port oder Bereich, Quelle, Kommentar (E-090) |
| `fail2ban_ignores` | Adressen und Netze, die fail2ban auf einem Server nie sperrt (E-090) |
| `sftp_keys` | Öffentliche SSH-Schlüssel für den SFTP-Zugang einer Website; Schalter und verschlüsseltes Passwort stehen in `sites` (E-089) |
| `backups` | Entfällt: Sicherungen stehen nur im restic-Repository je Server und werden live gelesen; das Panel speichert Ziel, Zeitplan, Repository-Passwort, Subaccount und Bereitschaft je Server (`servers`, E-097, E-100) und den Schalter je Website (`sites.backup_enabled`), Läufe als Aufgaben (E-096) |
| `server_tasks` | Aufträge mit Status, Log, Start und Ende, optional mit zugehöriger Website und vorheriger Aufgabe (`after_task_id`, E-106) |
| `servers` (Wartung) | Letzte Antwort von `server-updates` als JSON, Zeitpunkt und Fehler der Prüfung, Zustand von `certbot.timer`, Ablauf des Panel-Zertifikats (E-076) |
| `server_metrics` | Messwerte von `server-status` je Server und Minute: CPU, Load, Arbeitsspeicher, Swap, Festplatte, Netzwerkzähler; sieben Tage aufbewahrt (E-075) |
| `audit_logs` | Wer hat wann was ausgelöst |
| `push_subscriptions` | Geräte eines Administrators für Push-Nachrichten: Adresse des Push-Dienstes, Schlüssel, Bezeichnung (E-101) |
| `alerts` | Gemeldete Probleme mit Zeitpunkt der Meldung und Entwarnung (E-101) |
| `pending_notifications` | Nachrichten, die bis zum Ende der Ruhezeit warten (E-101) |
| `api_tokens` | Gehashte Tokens, Rechte, Ablauf, letzte Nutzung |
| `webhooks` | Ziel-URL, Ereignisse, Signaturschlüssel |
| `webhook_deliveries` | Zustellversuche und Ergebnis |

## Mehrsprachigkeit

- Laravel-Sprachdateien unter `lang/de` und `lang/en`
- Sprache pro Benutzer speicherbar
- Skripte liefern Fehlercodes im JSON-Ergebnis, das Panel übersetzt sie
- Technische Rohlogs bleiben unübersetzt

## Bewusste Einschränkung

SSH verlangt, dass das Panel den Port des Servers erreichen kann. Ein eigener Agent mit ausgehender Verbindung wäre für Server hinter NAT besser, ist aber für die erste Version zu aufwendig. Die Skripte bleiben davon unabhängig, sodass ein Agent sie später aufrufen könnte.
