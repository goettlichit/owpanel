# Tests der Serverskripte

## Lokal

Diese Prüfungen laufen ohne root und ändern nichts am System:

```bash
tests/check.sh
```

Dafür werden `shellcheck` und `bats` gebraucht. GitHub Actions führt dasselbe Skript bei jedem Pull Request und jedem Push auf `main` aus.

## Abnahmetest auf einer Wegwerf-VM

`tests/vm/acceptance.sh` führt alle Serverskripte zweimal aus und prüft danach Dienste, Firewall, die Einschränkungen von `owpanel-agent` und die Website. Es läuft nur, wenn die Datei `/etc/owpanel-test-vm` existiert. Lege sie ausschließlich auf einer Wegwerf-VM an.

```bash
touch /etc/owpanel-test-vm
cd /root/owpanel
tests/vm/acceptance.sh --domain <domain> --public-key-file /root/panel.pub
```

Die Zeitzone wird auf `Europe/Berlin` gesetzt, mit `--timezone <Gebiet/Ort>` auf eine andere. Der Website-Benutzer ist `ow10001`, mit `--user ow<Nummer>` lässt sich ein anderer wählen. Der Test läuft auf einer frischen wie auf einer schon eingerichteten VM. Mit `--no-tls` entfällt Let's Encrypt; ein Zertifikat aus einem früheren Lauf bleibt dabei in Gebrauch. `--delete-cycle` löscht die Website zusätzlich und legt sie neu an, das kostet ein weiteres Zertifikat. Let's Encrypt erlaubt 5 Zertifikate pro Domain in 7 Tagen. Die Ausgabe landet zusätzlich in `/root/owpanel-acceptance-<Zeit>.log`.

Die folgenden Abschnitte beschreiben dieselben Schritte einzeln.

## Auf einer Wegwerf-VM

Nur auf einer frischen Debian-13-VM ausführen, niemals auf einem Produktivsystem. Der Schlüssel ist ein eigener Test-Schlüssel, und die Datei `panel.pub` enthält nur dessen öffentlichen Teil.

```bash
# Als root auf der VM, im Repository
server/provision/setup-agent --public-key-file panel.pub
sudo -u owpanel-agent sudo -n /opt/owpanel/bin/server-provision
sudo -u owpanel-agent sudo -n /opt/owpanel/bin/firewall-confirm
```

Das Ergebnis steht jeweils als JSON in der letzten Ausgabezeile. Meldet `server-provision` `"firewall_confirm_required":true`, muss `firewall-confirm` innerhalb von 60 Sekunden folgen, sonst nimmt der Server die neuen Firewall-Regeln zurück.

Danach prüfen:

- Beide Befehle ein zweites Mal ausführen: Beide enden mit `"ok":true`.
- `systemctl is-active nginx mariadb fail2ban certbot.timer php*-fpm`
- `nft list table inet owpanel` zeigt SSH, 80 und 443 über TCP sowie 443 über UDP.
- `fail2ban-client status sshd` zeigt die aktive Jail.
- `ssh -i panel owpanel-agent@<VM> sudo -n /opt/owpanel/bin/server-provision` funktioniert.
- `ssh -i panel owpanel-agent@<VM> sudo -n /bin/bash` wird abgelehnt.
- `ss -tln` zeigt Port 3306 nur auf `127.0.0.1`.

## Website auf der Wegwerf-VM

Voraussetzung: Die Subdomain zeigt per A-Eintrag auf die öffentliche IPv4 der VM, und Port 80 ist aus dem Internet erreichbar. `<domain>` ist die Test-Subdomain.

```bash
cd /
sudo -u owpanel-agent sudo -n /opt/owpanel/bin/site-create --domain <domain> --type php --user ow10001 --tls
sudo -u owpanel-agent sudo -n /opt/owpanel/bin/site-create --domain <domain> --type php --user ow10001 --tls
```

Mit `--tls` akzeptierst du die Nutzungsbedingungen von Let's Encrypt. Der zweite Lauf meldet nur `unchanged`, und beide Läufe enden mit `"tls":true`.

Danach prüfen:

- `runuser -u ow10001 -- sh -c 'echo "<?php echo PHP_VERSION;" > /var/www/ow10001/htdocs/public/index.php'`
- `https://<domain>/` zeigt die PHP-Version, `http://<domain>/` leitet auf HTTPS um.
- `https://<domain>/.env` liefert 403.
- `certbot renew --dry-run` läuft ohne Fehler durch.

## Website löschen auf der Wegwerf-VM

```bash
cd /
sudo -u owpanel-agent sudo -n /opt/owpanel/bin/site-delete --user ow10001
sudo -u owpanel-agent sudo -n /opt/owpanel/bin/site-delete --user ow10001
```

Beide Läufe enden mit `"ok":true`. Danach prüfen:

- `getent passwd ow10001` gibt nichts aus, `id nginx` nennt die Gruppe `ow10001` nicht mehr.
- `/var/www/ow10001`, `/etc/nginx/owpanel-sites/ow10001.conf` und der Pool unter `/etc/php/*/fpm/pool.d/` fehlen.
- `certbot certificates` listet das Zertifikat `ow10001` nicht mehr.
- `ps -o user,args -C php-fpm8.5` zeigt den Pool `www` als `nobody`.

## HTTP/3 von außen prüfen

Der Abnahmetest prüft HTTP/3 auf der VM selbst, sofern dort `curl` HTTP/3 kann. Von außen geht es so:

- **Browser:** Die Website zweimal laden, dann in den Entwicklertools unter „Netzwerk“ die Spalte „Protokoll“ einblenden. Dort steht `h3`. Beim ersten Aufruf steht noch `h2`, weil der Browser HTTP/3 erst über den Header `Alt-Svc` erfährt.
- **curl mit HTTP/3:** `curl -sI --http3-only https://<domain>/` muss eine Antwort liefern. Die mitgelieferte `curl`-Version auf macOS kann meist kein HTTP/3, die von Homebrew schon.

Klappt es nur auf der VM, aber nicht von außen, ist meist UDP 443 in einer Firewall des Anbieters gesperrt, etwa in der Hetzner Cloud Firewall.
