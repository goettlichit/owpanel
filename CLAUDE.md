# Arbeitsanweisungen für Claude

## Projekt

OWPanel by goettlichIT (Open Web Panel) ist ein einfaches Server-Control-Panel für Debian mit Unterstützung für einzelne und mehrere Server. Die Oberfläche ist auf Deutsch und Englisch verfügbar.

Lies vor größeren Änderungen `docs/KONZEPT.md`, `docs/ARCHITEKTUR.md` und `docs/SICHERHEIT.md`, bei Änderungen an der Oberfläche auch `docs/DESIGN.md`.

## Zusammenarbeit

- Kommuniziere auf Deutsch. Code, Bezeichner und Commit-Nachrichten sind auf Englisch.
- Der Entwickler hat Erfahrung mit PHP, HTML und Debian-Servern. Erkläre Laravel-Konzepte und Bash-Details kurz, wenn sie neu eingeführt werden.
- Bevorzuge einfache Lösungen, die eine Einzelperson warten kann.
- Prüfe neue Funktionen gegen die Abgrenzung in `docs/KONZEPT.md`.
- Trage wichtige Änderungen in `docs/ENTSCHEIDUNGEN.md` ein und halte `docs/ROADMAP.md` aktuell.

## Technische Regeln

- Standalone und Multi-Server nutzen denselben Codepfad. Die Ersteinrichtung ergibt eine Standalone-Installation, die sich ohne Migration erweitern lässt.
- Sichtbare Texte stehen immer in den Sprachdateien für `de` und `en`.
- Die Oberfläche nutzt Tabler und Inter. Assets werden lokal ausgeliefert, es gibt keine CDNs. Details stehen in `docs/DESIGN.md`.
- Pocket ID ist eine optionale Alternative zur Anmeldung mit Passwort und Zwei-Faktor. Das Panel muss ohne Pocket ID vollständig nutzbar sein.
- Das Projekt steht unter AGPL-3.0-or-later. Prüfe die Lizenz neuer Abhängigkeiten vor der Aufnahme.
- Das Repository ist für die Veröffentlichung gedacht. Es enthält keine Geheimnisse oder persönlichen Daten.
- Jeder Zugriff auf Kundendaten wird zentral über Policies auf die Zugehörigkeit geprüft. Neue Kundenfunktionen brauchen einen Test gegen Zugriffe auf fremde Daten.
- Abrechnung und Kundenstammdaten gehören nicht ins Panel, sondern über die API in ein externes System.
- Websites mit Zertifikat erhalten immer HTTP/3 über QUIC mit HTTP/2 und HTTP/1.1 als Rückfall. Vorlagen folgen `docs/ARCHITEKTUR.md`, Abschnitt „HTTP/3 und QUIC“.
- Die Firewall nutzt nftables (E-044). UDP 443 bleibt für QUIC offen, und jede Regeländerung wird automatisch zurückgenommen, wenn die SSH-Verbindung danach nicht bestätigt wird.
- Lange Serveraktionen laufen als Queue-Job mit sichtbarem Status und Log.
- Serverskripte müssen wiederholbar sein und validieren ihre Eingaben selbst.
- Das Panel führt auf Servern nur freigegebene Skripte aus.
- Generierte Konfigurationen werden vor der Aktivierung geprüft. Bei Fehlern wird die vorherige Fassung wiederhergestellt.
- Testet Serverskripte nur auf Wegwerf-VMs, niemals auf Produktivsystemen.

## Sicherheit

Die Regeln in `docs/SICHERHEIT.md` sind verbindlich. Sie dürfen nicht für Komfort umgangen werden. Eine bewusste Ausnahme braucht eine eigene Entscheidung in `docs/ENTSCHEIDUNGEN.md`.
