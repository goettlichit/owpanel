# Sicherheitsregeln

Diese Regeln sind verbindlich. Eine Ausnahme braucht eine eigene Entscheidung in [ENTSCHEIDUNGEN.md](ENTSCHEIDUNGEN.md).

Wer das Panel übernimmt, kann alle verbundenen Server steuern. Deshalb gilt es als besonders schützenswert.

## Rechte auf den Servern

1. Der PHP-Prozess des Panels läuft nie als root.
2. Das Panel meldet sich nie als root an und speichert keine Root-Passwörter.
3. Der Root-Login per SSH wird nicht für den Panelbetrieb verwendet.
4. `owpanel-agent` darf per `sudo` nur freigegebene Skripte unter `/opt/owpanel/bin/` starten.
5. Die `sudo`-Regeln nennen keine Shell, keinen Editor und keinen Paketmanager.
6. Die Skripte und ihre Verzeichnisse gehören root und sind für `owpanel-agent` nicht schreibbar.
7. Das Panel übergibt keine frei formulierten Shell-Befehle.

## Eingaben

1. Eingaben werden im Panel und im Serverskript validiert.
2. Skripte nutzen Positivlisten und strenge Muster, etwa für Domains und PHP-Versionen.
3. Pfade werden auf ihren erlaubten Basisordner geprüft.
4. Parameter werden im PHP-Code immer einzeln escaped, Variablen in Bash immer in Anführungszeichen gesetzt.
5. Skripte verwenden kein `eval`.
6. Bash-Skripte beginnen mit `set -euo pipefail`.

## Anmeldung am Panel

1. Zwei-Faktor-Authentifizierung ist für Administratoren bei der Passwort-Anmeldung Pflicht. Für andere Benutzer ist sie freiwillig.
2. Fehlversuche werden begrenzt. Jede Route, die etwas ändert oder sendet, hat ein Anfragelimit; ein Test prüft das für neue Routen (E-156).
3. Passwörter werden mit Laravels Hashing gespeichert.
4. Zugriffe erfolgen nur über HTTPS. Das Panel sendet HSTS und eine Content Security Policy ohne Inline-Skripte und ohne `eval` (E-157). Neue Oberflächenlogik steht in eigenen Skriptdateien, nicht im Markup.
5. Sessions sind auf sichere Cookies beschränkt und haben eine begrenzte Laufzeit.
6. Formulare nutzen Laravels CSRF-Schutz.
7. Weitere Administratoren legt nur ein Administrator an (E-081). Das Panel erzeugt ein zufälliges Start-Passwort, zeigt es einmal an und speichert es nirgends im Klartext. Es muss bei der ersten Anmeldung geändert werden, danach folgt die Einrichtung der Zwei-Faktor-Authentifizierung; vorher ist nichts anderes möglich.
8. Niemand löscht sein eigenes Konto. Der letzte Administrator mit Zwei-Faktor lässt sich nicht löschen (siehe auch „Pocket ID und OIDC“, Regel 8).
9. Passwort zurücksetzen gibt es nur, wenn ein SMTP-Server eingerichtet ist (E-094). Die Antwort verrät nicht, ob es ein Konto mit der Adresse gibt; die Mail geht über die Warteschlange, damit auch die Antwortzeit nichts verrät. Der Link gilt 60 Minuten und einmal. Anfragen sind je Adresse und je IP begrenzt. Das Zurücksetzen ändert nur das Passwort: Die Zwei-Faktor-Bestätigung bleibt Pflicht.
10. Die SMTP-Einstellungen bestimmen, wohin Links zum Zurücksetzen gehen. Nur Administratoren ändern sie; das SMTP-Passwort wird verschlüsselt gespeichert und nie angezeigt. STARTTLS ist Vorgabe und wird erzwungen; ohne Verschlüsselung nur für einen Server im eigenen Netz.

## Pocket ID und OIDC

Grundlage ist Entscheidung E-017.

Grundlage der Umsetzung sind E-113 und E-114.

1. Pocket ID ist eine optionale Alternative zur Passwort-Anmeldung. Das Panel funktioniert auch ohne. Eine erfolgreiche Anmeldung darüber verlangt keine zusätzliche Panel-Zwei-Faktor-Abfrage.
2. Es wird der Authorization Code Flow mit PKCE (S256) sowie `state` und `nonce` verwendet. Alle drei sind je Versuch neu, liegen nur in der Sitzung und gelten einmal.
3. Aussteller und Redirect-URL werden exakt geprüft und nur über HTTPS akzeptiert.
4. Tokens werden nach den OIDC-Regeln geprüft, insbesondere Signatur, Aussteller, Zielgruppe und Ablauf. Nur asymmetrische Signaturverfahren sind erlaubt, nie `none` oder HS*.
5. Benutzer werden über Aussteller und `sub` zugeordnet, nie über die E-Mail-Adresse.
6. Eine erfolgreiche OIDC-Anmeldung legt keine Konten an und vergibt keine Administratorrechte.
7. Verknüpfen und Trennen setzen eine aktive Anmeldung voraus und erscheinen im Audit-Log. Ausnahme für Kunden (E-130, E-138): Ein Administrator verknüpft ein Pocket-ID-Konto, das das Panel selbst angelegt hat, oder eines, das er nach Name und Adresse bestätigt hat und dessen Adresse noch der des Kunden entspricht. Der API-Schlüssel von Pocket ID ist optional, wird verschlüsselt gespeichert und soll nur die Rechte `users:read`, `users:write` und `users:login-codes` haben.
8. Mindestens ein lokaler Administrator mit Zwei-Faktor bleibt als Notfallzugang erhalten. Das Panel verhindert, dass der letzte gelöscht wird.
9. Das Client-Secret wird verschlüsselt gespeichert.
10. Kundenkonten nutzen Pocket ID erst nach Freischaltung durch einen Administrator; Sperren entfernt eine bestehende Verknüpfung.
11. Das Panel fragt nirgends ein zweites Mal nach dem Passwort (E-135, bewusste Ausnahme). Eine offene Sitzung reicht deshalb für alle Aktionen ihrer Rolle; Schutz bieten die Zwei-Faktor-Anmeldung, die Abmeldung nach Inaktivität und das Audit-Log.

## Kundenbereich

Grundlage ist Entscheidung E-021.

1. Jeder Zugriff auf Websites, Datenbanken, Backups und Logs prüft, ob sie zum angemeldeten Kunden gehören. Das geschieht zentral über Laravel-Policies, nicht in einzelnen Ansichten.
2. Automatische Tests versuchen für jede Kundenfunktion, auf Daten eines anderen Kunden zuzugreifen.
3. URLs verwenden nicht erratbare IDs wie ULIDs statt fortlaufender Nummern.
4. Kunden sehen keine Server, Firewall-Regeln, anderen Kunden oder Panel-Einstellungen. Seiten der Verwaltung verlangen zusätzlich zur Policy die Rolle Administrator (Route-Middleware `role:admin`), der Kundenbereich unter `/my` die Rolle Kunde (E-143). Aufgaben-Logs sehen nur Administratoren.
5. Kunden können keine Konfigurationsdateien oder Serverskripte beeinflussen, nur die freigegebenen Einstellungen ihrer Websites.
6. Das Panel speichert über Kunden nur Vor- und Nachname, Firma, E-Mail, Sprache, die eigene Kundennummer und die externe Kundennummer (E-085).
7. Beim Löschen eines Kunden werden seine personenbezogenen Daten entfernt. Das Audit-Log behält nur die Kunden-ID.
8. Grenzen aus Paketen (E-125) prüfen die Aktionen und Policies auf dem Server, nicht nur die Oberfläche.
9. Eine gesperrte Website (E-127) lässt sich vom Kunden nur ansehen; geprüft zentral für jede ändernde Anfrage (`GuardSuspendedSites`, E-151). Die Sperrseite nennt keinen Grund, und die interne Notiz sieht nur der Administrator. Die API hebt nur Sperren auf, die sie selbst gesetzt hat.
10. Ein gesperrter Zugang (`users.locked_at`) wird bei jeder Anmeldung abgelehnt, mit Passwort wie mit Pocket ID, und seine offenen Sitzungen werden beendet.
11. Kunden erhalten ihren Zugang über einen Einladungslink, der 72 Stunden und einmal gilt, oder über ein einmal angezeigtes Start-Passwort, das sie ändern müssen (E-128). Das Panel speichert vom Link nur den SHA-256-Wert; eine neue Einladung macht den alten Link ungültig. Ein Administrator kann ein neues Start-Passwort erzeugen (E-140); es wird einmal angezeigt, muss geändert werden und beendet offene Sitzungen sowie eine offene Einladung.
12. Die Quotas in `/etc/fstab` richtet nur die Grundeinrichtung ein: gesicherte Fassung, Prüfung mit `findmnt --verify`, Rücknahme bei Fehlern (E-126). `site-quota` nimmt nur einen OWPanel-Website-Benutzer (E-034) und eine Zahl an.

## API und Webhooks

Grundlage ist Entscheidung E-024.

1. API-Tokens werden nur gehasht gespeichert und bei der Erstellung einmal angezeigt.
2. Jedes Token hat nur die Rechte, die es braucht, und kann eine Ablaufzeit und eine IP-Beschränkung haben.
3. Die API ist nur über HTTPS erreichbar und begrenzt die Anzahl der Anfragen.
4. Jeder API-Aufruf mit Änderung erscheint im Audit-Log mit dem verwendeten Token.
5. Websites ohne Kundenzuordnung sind für API-Tokens nicht erreichbar.
6. Webhooks werden nur an HTTPS-Adressen gesendet und mit HMAC und Zeitstempel signiert, damit der Empfänger Echtheit und Aktualität prüfen kann.
7. Webhook-Ziele dürfen nicht auf interne Adressen wie `127.0.0.1` oder private Netze zeigen.
8. Dasselbe gilt für jede andere Adresse, die das Panel im Auftrag eines Administrators aufruft, etwa den Heartbeat (E-160): nur HTTPS, Prüfung der aufgelösten IP vor jedem Aufruf und Bindung des Aufrufs an diese IP, keine Weiterleitungen.
9. In der ersten Stufe (E-165) liest die API nur und schreibt ausschließlich den Zahlungshinweis eines Kunden. Sie legt keine Websites an, sperrt keine und nennt keine Server.

## Geheimnisse

1. Der private SSH-Schlüssel liegt außerhalb des Webroots und ist nur für den Worker-Benutzer lesbar. Der Worker läuft als `owpanel-worker`, die Webanfragen des Panels als `ow10000` (E-070).
2. Gespeicherte Passwörter und Tokens werden verschlüsselt abgelegt.
3. Geheimnisse erscheinen nie in Logs, Fehlermeldungen oder Prozessparametern.
4. Sie werden an Skripte über die Standardeingabe statt über die Befehlszeile übergeben.
5. Backups sensibler Daten werden verschlüsselt.

## Öffentliches Repository

1. Im Repository stehen keine Geheimnisse, weder im Code noch in Tests oder der Commit-Historie.
2. Jede Installation erzeugt eigene Schlüssel und Passwörter. Es gibt keine Standardzugänge.
3. Die Sicherheit hängt nicht davon ab, dass der Code geheim bleibt.
4. Sicherheitslücken werden privat gemeldet, siehe [SECURITY.md](../SECURITY.md).
5. Abhängigkeiten werden mit einer Lock-Datei festgeschrieben.

## Ersteinrichtung und Einbindung neuer Server

1. Einmal-Tokens für die Einbindung neuer Server sind kurz gültig und werden nach Gebrauch entwertet. Für die Ersteinrichtung gibt es keine Adresse im Browser: Der Installer fragt alles im Terminal ab (E-069).
2. Bootstrap-Dateien werden nur über HTTPS geladen.
3. Das Skript prüft die Integrität der geladenen Dateien mit einer Prüfsumme.
4. Das Panel speichert den SSH-Host-Schlüssel und prüft ihn bei jeder Verbindung.
5. Releases werden in GitHub Actions in einem eigenen Job signiert, der keinen fremden Code ausführt; der private Schlüssel steht nur diesem Job zur Verfügung. `get-owpanel` prüft die Signatur gegen einen fest eingetragenen öffentlichen Schlüssel, der Installer hinterlegt ihn in `/etc/owpanel/release-signers`. Serverskripte installiert `server-scripts-update` nur mit gültiger Signatur, gleich ob das Archiv vom Panel oder von GitHub kommt; das Panel selbst kann keine Skripte unterschieben (E-167, E-187). Testbauten ohne Signatur nimmt nur `--dev` an, mit Warnung; `server-scripts-update` nimmt sie nur auf einem Server, dessen Installer selbst ein Testbau war (`/etc/owpanel/allow-unsigned`, legt nur root an), und nie anstelle eines Releases. Tags für Releases setzt nur, wer Schreibrecht auf das Repository hat, über den Workflow „Tag release“ oder von Hand; ein Token mit Schreibrecht auf das Repository liegt auf keinem Server (E-190).
6. Weitere Server (geplant, E-166): Das Einmal-Token fragt der Installer ab, es steht nie in Parametern. Das Panel liefert Skripte und seinen öffentlichen Schlüssel nur gegen ein gültiges Token und prüft danach SSH gegen den gemeldeten Host-Schlüssel.
7. Das Panel aktualisiert sich nur über `panel-update` (E-185): getaggte Releases von den GitHub-Releases des Projekts, nur neuere Versionen, geprüft mit Prüfsumme und Signatur durch root auf dem Server, bevor etwas entpackt wird. Das Panel nennt nur die Versionsnummer, nie eine Adresse oder Datei.

## Multi-Server (geplant)

Grundlage sind E-161 bis E-168. Die Regeln gelten ab der Umsetzung in Meilenstein 5.

1. Weitere Server hängen im privaten Hetzner-Netz des Panel-Servers. Das Panel steuert sie nur über die private Adresse. Hetzner trennt Netze verschiedener Projekte, verschlüsselt sie aber nicht; deshalb laufen über das Netz nur SSH und HTTPS vom Gateway, nie etwas im Klartext. Das Gateway prüft das Zertifikat jedes Website-Servers gegen das beim Einbinden gemeldete (E-186), so wie das Panel den SSH-Host-Schlüssel prüft.
2. Auf Website-Servern ist öffentlich nur Port 22 für SSH und SFTP offen. HTTP und alles andere nehmen sie nur aus dem privaten Subnetz an. Die Regeln für SSH und die automatische Rücknahme der Firewall gelten unverändert.
3. Der NGINX eines Website-Servers übernimmt die Besucheradresse aus Kopfzeilen nur von der privaten Adresse des Gateways (`set_real_ip_from`), nie von anderen Absendern.
4. Ein Panel steuert keine ändernden Aufgaben auf einem Server, dessen Skriptversion abweicht (E-167).

## Docker-Apps (geplant)

Grundlage ist E-163. Die Regeln gelten ab Meilenstein 9.

1. Apps legen und steuern nur Administratoren. Kunden sehen und erreichen keine Apps und keine Docker-Funktionen.
2. Docker wird nur über freigegebene Skripte gesteuert. `owpanel-agent` gehört nie zur Gruppe `docker`.
3. Container veröffentlichen Ports nur auf `127.0.0.1` oder der privaten Adresse des Servers; das Skript lehnt andere Adressen ab, weil Docker veröffentlichte Ports an der Firewall des Panels vorbei freigibt.
4. Daten einer App liegen nur unter `/srv/owpanel/apps/<name>/`, gehören root und werden mit restic gesichert.
5. Docker kommt nur auf Server, auf denen ein Administrator es einschaltet.

## Website-Isolation

1. Jede Website hat einen eigenen Linux-Benutzer und PHP-FPM-Pool.
2. Linux-Benutzernamen erhalten eine zufällige Nummer (E-034) und enthalten keine Kundennamen, Domains oder fortlaufenden IDs.
3. Fremde Website-Verzeichnisse sind nicht lesbar.
4. Datenbankbenutzer erhalten nur Rechte auf ihre eigene Datenbank.
5. Eigene Regeln für NGINX (E-184) setzen nur Administratoren, und nur als Header (`Name: Wert`) und gesperrte Pfade. Freier NGINX-Text ist ausgeschlossen, weil Anweisungen wie `access_log`, `include` oder `alias` Dateien als root schreiben oder fremde Dateien ausliefern könnten. Panel und `site-nginx` prüfen jede Zeile mit denselben Mustern: Namen nur aus Buchstaben, Ziffern und Bindestrichen, ohne die Header, die OWPanel selbst setzt; Werte nur druckbares ASCII ohne `"`, `\`, `$`, `{` und `}`; Pfade nur aus Buchstaben, Ziffern und `._~/-`, ohne `//` und ohne Teile mit Punkt am Anfang. Das Skript schreibt sie in fester Form, prüft mit `nginx -t` und stellt bei Fehlern die vorherigen Regeln wieder her.

## SFTP

Grundlage ist Entscheidung E-089.

1. SFTP-Zugänge erlauben nur `internal-sftp` im eigenen Website-Verzeichnis: keine Shell, kein TTY, keine Weiterleitungen. Das gilt über die Gruppe `owpanel-sftp` und eine eigene Datei in `sshd_config.d`, die vor dem Laden mit `sshd -t` geprüft wird.
2. Passwortanmeldung gilt nur für Mitglieder dieser Gruppe, nicht für andere Konten des Servers.
3. Schlüsseldateien liegen außerhalb des Chroots, gehören root und sind für den Website-Benutzer nur lesbar. Jeder Schlüssel erhält `restrict`, Optionen aus dem Panel werden nicht angenommen.
4. Das Passwort wird zufällig erzeugt, verschlüsselt gespeichert und nur auf Anforderung angezeigt; jedes Anzeigen steht im Audit-Log. Zum Server gelangt es nur über die Standardeingabe.
5. Ausschalten, ein neues Passwort oder ein gelöschter Schlüssel trennen offene SFTP-Sitzungen der Website.

6. Die Passwort-Anmeldung per SSH für Administratoren schaltet das Panel nur ab, wenn root und jedes andere Konto mit Anmeldung einen SSH-Schlüssel hat; die Konfiguration wird mit `sshd -t` geprüft und bei Fehlern zurückgenommen (E-155). SFTP-Kunden behalten ihr Passwort.
## Datenbank-Passwörter

Grundlage ist Entscheidung E-078.

1. Das Panel erzeugt Datenbank-Passwörter zufällig und speichert sie nur verschlüsselt mit dem App-Schlüssel.
2. Ein Passwort wird nur auf Anforderung angezeigt, und jedes Anzeigen steht im Audit-Log.
3. Zum Server gelangt ein Passwort nur über die Standardeingabe eines Skripts und von dort über die Standardeingabe an `mariadb`, nie als Parameter. Es steht weder in der Prozessliste noch in Aufgaben, Logs oder dem Audit-Log.

## Firewall und Netzwerk

Grundlage sind die Entscheidungen E-026, E-027, E-044 und E-090.

1. Eingehender Verkehr ist standardmäßig gesperrt. Offen sind nur die Ports aus `ARCHITEKTUR.md`.
2. Die SSH-Freigabe kann im Panel nicht gelöscht werden, nur auf einen anderen Port geändert.
3. Neue Regeln werden vor dem Laden mit `nft -c -f` geprüft.
4. Bestätigt das Panel eine Änderung nicht innerhalb von 60 Sekunden über eine neue SSH-Verbindung mit `firewall-confirm`, stellt der Server die vorherigen Regeln automatisch wieder her. Erst die Bestätigung speichert die Regeln für einen Neustart.
5. Die Regeln gelten für IPv4 und IPv6 gleichermaßen.
6. 0-RTT bei TLS und QUIC bleibt ausgeschaltet, weil damit gesendete Anfragen von Angreifern wiederholt werden könnten.
7. Die Datei `quic_host_key` gehört root und ist nur für root lesbar.
8. Unbekannte Domains werden mit `ssl_reject_handshake` abgewiesen und erhalten kein fremdes Zertifikat.
9. Eigene Regeln aus dem Panel können nur zusätzlich Ports öffnen, nie sperren. Das Skript nimmt nur Protokoll, Port und Adresse an und schreibt den Kommentar nicht in die Regeln. Sperren gibt es nur über Sperrlisten (Regel 11).
10. fail2ban-Ausnahmen gelten für einzelne Adressen oder Netze. Eine Ausnahme schützt auch Angreifer aus diesem Netz vor Sperren; die Oberfläche weist darauf hin, keine wechselnden Adressen einzutragen. Gesperrte Adressen werden nur angezeigt, nicht gespeichert, und Adressen stehen nicht im Audit-Log.
11. Sperrlisten (E-170) lädt nur das Panel, nur über HTTPS mit `PublicUrl` (öffentliche Adressen, gepinnt, ohne Weiterleitungen) und höchstens 16 MiB. Es übernimmt nur Adressen und Netze, keine privaten oder reservierten Netze und keine größer als /8 (IPv4) bzw. /16 (IPv6). `server-blocklist` prüft jeden Eintrag selbst, lädt sie in eine eigene Tabelle `inet owpanel_block`, deren Kette nur verwirft, und stellt fail2ban-Ausnahmen und die Adressen des Panel-Servers als Ausnahmen davor. Eine Liste mit der Adresse des eintragenden Administrators lehnt das Panel ab, solange diese keine fail2ban-Ausnahme ist. Das Laden von Sperrlisten ist von der Rücknahme nach Regel 4 ausgenommen, weil es keine Freigabe ändert. Welche Adressen abgewiesen wurden, liest das Panel nur zur Anzeige und speichert es nicht; auf dem Server fallen sie nach 7 Tagen ohne Treffer heraus.

## Datenbank-Werkzeug

Grundlage ist Entscheidung E-079.

1. Abfragen laufen als Datenbankbenutzer der jeweiligen Datenbank, nie als root von MariaDB, und auf dem Server als Linux-Benutzer der Website, nie als root.
2. Wer das Werkzeug einer Datenbank nutzen darf, entscheidet die `DatabasePolicy`. Ein Test prüft den Zugriff auf fremde Datenbanken.
3. Webanfragen verbinden sich nicht selbst mit Servern; das erledigt der Abfrage-Worker. SQL und Antwort liegen dazwischen nur verschlüsselt in Queue und Cache. Passwort und SQL gelangen nur über die Standardeingabe zum Server. Der SQL-Text steht nicht im Audit-Log, weil er persönliche Daten oder Passwörter enthalten kann.
4. Namen von Tabellen setzt das Panel immer in Backticks, Werte als Hex-Literale. Ergebnisse werden in der Oberfläche immer escaped ausgegeben.
5. Die Antwort des Servers ist begrenzt (Zeilen, Zellen, Gesamtgröße, Laufzeit) und wird vor der Anzeige auf ihre Form geprüft.
6. Export und Import laufen ebenfalls als Datenbankbenutzer (E-082). Ein Import erreicht so keine andere Datenbank; `CREATE DATABASE` oder fremde Tabellen scheitern an den Rechten.
7. Export- und Importdateien liegen nur im Transferverzeichnis des Panels (`shared/storage/app/transfers`, Gruppe `ow10000`, kein Zugriff von außen), tragen zufällige Namen und werden nach spätestens 24 Stunden gelöscht, Importdateien gleich nach dem Import. Herunterladen geht nur über die Policy der Datenbank und steht im Audit-Log.

## Änderungen und Nachvollziehbarkeit

1. Jede sicherheitsrelevante Aktion erscheint im Audit-Log.
2. Generierte Konfigurationen werden vor der Aktivierung geprüft.
3. Bei einem Fehler wird die vorherige Fassung wiederhergestellt.
4. Abhängigkeiten werden regelmäßig auf bekannte Sicherheitslücken geprüft.
5. Serverskripte werden nur auf Wegwerf-VMs getestet.

## Vor der ersten Veröffentlichung

- ShellCheck läuft ohne ungeklärte Warnungen.
- Tests decken manipulierte Domains, Pfade und Parameter ab.
- Tests zeigen, dass Kunden und API-Tokens keine fremden Daten erreichen.
- Die `sudo`-Regeln wurden von Hand geprüft.
- Eine unabhängige Sicherheitsprüfung wurde durchgeführt.
- Die private Meldung von Sicherheitslücken ist auf GitHub aktiviert.
- Ein Scan der Commit-Historie hat keine Geheimnisse gefunden.

## Besucherstatistik

Grundlage ist Entscheidung E-148.

1. Gezählt wird nur auf dem Server aus den vorhandenen Access-Logs. Das Panel bindet nichts in die Websites ein, setzt keine Cookies und speichert keine IP-Adressen oder Browserkennungen.
2. `site-stats` hasht Adresse und Browser nur zum Zählen, mit einem Schlüssel, der nur während des Laufs existiert, und gibt nur Zahlen und Pfade aus.
3. Die Statistik sehen vorerst nur Administratoren.

## Löschfristen

Grundlage ist Entscheidung E-092.

1. Audit-Log: fehlgeschlagene Anmeldungen 90 Tage, alle anderen Einträge ein Jahr.
2. „Angemeldet bleiben“: 30 Tage.
3. Systemjournal, NGINX-Logs und Logs des Panels: 14 Tage.
4. fail2ban hält eine Adresse nur für die Dauer der Sperre.
5. Abgeschlossene Aufgaben samt Ausgabe: 90 Tage (E-093).
6. Besucherzahlen je Website und Tag: 14 Monate (E-148).

## Hetzner Cloud API

Grundlage ist Entscheidung E-095.

1. Der API-Token gilt für ein ganzes Hetzner-Projekt. Er wird beim Speichern mit einer Leseanfrage geprüft, verschlüsselt gespeichert und nie angezeigt; nur Administratoren ändern ihn.
2. Das Panel spricht nur mit `api.hetzner.cloud` (Snapshots, DNS-Zonen, Reverse DNS) und `api.hetzner.com` (Storage Box, E-097) über HTTPS, aus dem Worker und aus Seitenaufrufen der Administratoren. Dazu kommt `api.github.com`, nur lesend und ohne Token, für die neueste veröffentlichte Version des Panels (E-154).
3. Das Panel löscht automatisch nur Snapshots nach Zeitplan mit seinen Labels und der ID des Servers; der neueste bleibt immer. Snapshots, die im Panel von Hand erstellt wurden, löscht nur ein Mensch. Snapshots aus der Hetzner-Konsole fasst das Panel nie an.
4. Ein eigener Token für das Projekt mit den DNS-Zonen ist optional (E-118) und gilt wie der Projekt-Token: geprüft, verschlüsselt, nie angezeigt, nur Administratoren ändern ihn.
5. In DNS-Zonen ändert das Panel nur A- und AAAA-Einträge von Websites (E-116) und des Hostnamens eines Servers (E-153), Reverse DNS nur für die Adressen des Servers selbst: neu nur fehlende beim Anlegen einer Website, überschreiben nur nach einer Bestätigung mit altem und neuem Wert, löschen nur Einträge, die es selbst gesetzt hat und die noch genau seinen Wert enthalten. Jede Änderung steht im Audit-Log.

## Sicherungen

Grundlage sind die Entscheidungen E-095 bis E-100.

1. Jeder Server sichert mit einem eigenen SSH-Schlüssel über einen eigenen Subaccount der Storage Box in ein eigenes, verschlüsseltes restic-Repository. Der Subaccount sieht nur das Verzeichnis dieses Servers; ein Server kann die Sicherungen anderer Server weder lesen noch löschen.
2. Schlüssel, Repository-Passwort und SSH-Konfiguration liegen in `/etc/owpanel/backup/`, nur für root lesbar. Das Panel hält eine verschlüsselte Kopie des Repository-Passworts; ohne dieses Passwort sind die Sicherungen unlesbar.
3. Das Panel kennt kein Passwort der Storage Box. Subaccounts legt es mit dem API-Token an; deren Zufallspasswort entsteht beim Verbinden neu, geht einmal über die Standardeingabe an den Server, um den Schlüssel zu hinterlegen, und wird nicht gespeichert. Subaccounts haben nur SSH, kein Samba und kein WebDAV, und sind standardmäßig nur aus dem Hetzner-Netz erreichbar; die Storage Box selbst ist von außen erreichbar, solange ein Server sie so braucht.
4. Datenbank-Dumps entstehen in einem Verzeichnis nur für root und werden nach jeder Sicherung gelöscht, auch nach einem Fehler.
5. Wer root auf einem Server erlangt, kann dessen eigene Sicherungen löschen. Automatische Snapshots der Storage Box schützen davor; die Backup-Seite des Servers empfiehlt sie.
6. Eine Wiederherstellung nimmt nur Sicherungen mit dem Tag der eigenen Website an. Vor jeder Änderung sichert der Server den aktuellen Stand.
7. Dateien kopiert der Benutzer der Website zurück, nie root; so kann ein Symlink in `htdocs` keine Datei außerhalb der Website treffen. Ausgepackt wird vorher in ein Verzeichnis nur für root.
8. Ein Datenbank-Export wird nur eingespielt, wenn die Datenbank zur Website gehört, noch existiert und der Export keine andere Datenbank nennt.
9. Die Sicherung des Panels enthält Datenbank und `APP_KEY` zusammen und damit alle Passwörter, die das Panel kennt. Sie liegt nur verschlüsselt im Repository des Panel-Servers; dessen Repository-Passwort bewahrt der Betreiber außerhalb auf.
10. Die Wiederherstellung des Panels bietet nur der Installer an, bei einer ersten Installation im Terminal. Er prüft die Sicherung, bevor er etwas installiert, hält das Repository-Passwort und das Passwort des Subaccounts nur bis zum Abruf in Dateien nur für root (das Passwort des Subaccounts gibt `sshpass -f` an SSH, nie über die Kommandozeile) und löscht die ausgepackte Sicherung am Ende. Ein Datenbank-Export wird nur eingespielt, wenn er allein die Datenbank `owpanel` nennt.
11. Repository-Passwörter zeigt das Panel nur in den Notfalldaten (alle Repository-Passwörter als Textdatei); Server → Backups verlangt das Passwort nicht erneut und zeigt deshalb keines. Die Notfalldaten gibt es nur für Administratoren, per POST (ohne erneute Passworteingabe, E-135), ohne Zwischenspeicher und mit Eintrag im Audit-Log. Sie enthalten weder den Hetzner-Token noch den `APP_KEY`.

## Benachrichtigungen

Grundlage ist E-101.

1. Nur Administratoren erhalten Nachrichten und sehen die Seite Benachrichtigungen. Jeder verwaltet nur die eigenen Geräte; Policies prüfen das, Tests decken Zugriffe von Kunden und fremden Administratoren ab.
2. Push-Nachrichten sind Ende-zu-Ende-verschlüsselt (RFC 8291). Der Push-Dienst des Browsers (Apple, Google, Mozilla, Microsoft) sieht Zeitpunkt, Größe und Gerät, nicht den Inhalt. Nachrichten nennen nur Server- und Website-Namen, nie Passwörter oder Pfade.
3. Das Panel sendet Push-Nachrichten nur an https-Adressen bekannter Push-Dienste (`PushSubscription::ALLOWED_HOSTS`), ohne Weiterleitungen. Andere Adressen lehnt es beim Einrichten ab und löscht sie beim Senden.
4. Der private VAPID-Schlüssel liegt verschlüsselt mit dem `APP_KEY` in den Einstellungen. Wer ihn hat, kann nur an bereits eingerichtete Geräte des Panels senden, nicht mitlesen.
5. Hinzufügen und Entfernen von Geräten steht im Audit-Log. Geräte, die der Push-Dienst nicht mehr kennt (404, 410) oder die zehnmal hintereinander scheitern, löscht das Panel.
6. Der Service Worker (`/sw.js`) speichert keine Seiten und öffnet beim Antippen nur Adressen des Panels selbst.

