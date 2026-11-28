# Wiederherstellung

Diese Anleitung beschreibt, wie du OWPanel nach einem Totalausfall auf einem neuen Server zurückholst (E-099), auf Wunsch zusammen mit den Websites dieses Servers (E-106). Für einzelne Websites brauchst du sie nicht. Websites stellst du im Panel unter Website → Backups wieder her (E-098).

## Was du vorher aufbewahren musst

Lege diese Angaben jetzt in deinem Passwortmanager ab, nicht erst im Notfall. Am einfachsten lädst du sie unter System → Backup mit „Notfalldaten herunterladen“ als Textdatei herunter. Lade sie nach jeder neuen Verbindung eines Servers erneut herunter.

- das **Repository-Passwort des Panel-Servers**
- den **Subaccount des Panel-Servers**, z. B. `u123456-sub1` (steht in derselben Zeile)
- den **Hostnamen der Storage Box**, z. B. `u123456.your-storagebox.de`

Ohne das Repository-Passwort sind die Sicherungen nicht lesbar. Weder Hetzner noch OWPanel können es wiederherstellen.

## Was die Sicherung enthält

Jeden Tag und bei „Jetzt sichern“ sichert der Panel-Server:

- die Datenbank `owpanel` mit allen Servern, Websites, Konten, Einstellungen und den verschlüsselten Passwörtern
- `/var/www/ow10000/shared/.env` mit dem `APP_KEY`, der diese Passwörter entschlüsselt
- `/etc/owpanel/panel.conf` mit den Angaben des Installers, etwa der Domain
- `/var/lib/owpanel-worker/ssh/` mit dem SSH-Schlüssel, mit dem das Panel alle Server erreicht

Die Websites auf dem Panel-Server sichert der Server wie jeder andere Server separat. Die Sicherung des Panels enthält nur, was das Panel über sie weiß: Domain, PHP-Version, Datenbanken mit ihren Passwörtern, Cronjobs, SFTP-Zugang sowie die eigenen Firewall-Regeln und fail2ban-Ausnahmen des Servers. Dateien und Inhalte der Datenbanken stecken in den Sicherungen der Websites. Aus beidem baut das Panel die Websites wieder auf (E-106).

## Schritte

1. **Neuen Server bereitstellen.** Debian 13, wie bei der ersten Installation. Den DNS-Eintrag der Panel-Domain auf den neuen Server zeigen lassen.

2. **Passwort des Subaccounts zurücksetzen.** In der Hetzner Console bei der Storage Box unter „Subaccounts“ den Subaccount des alten Panel-Servers suchen. Er hat das Label `owpanel=backup`, und seine Beschreibung nennt den Server. Ein neues Passwort setzen. Steht der neue Server nicht bei Hetzner, dort auch „Extern erreichbar“ einschalten.

3. **Installer starten**, wie im README unter „Installation“ beschrieben (`get-owpanel`). Bei einer ersten Installation fragt er zuerst:

   ```
   Restore from a backup? [y/N]
   ```

   Mit `y` fragt er nach dem Hostnamen der Storage Box, dem Subaccount, dem Passwort des Subaccounts aus Schritt 2, dem Repository im Subaccount (Vorgabe `restic`, einfach mit Enter bestätigen) und dem Repository-Passwort. Gibt man statt `restic` das Verzeichnis auf der Storage Box aus den Notfalldaten ein (`owpanel/<Server-ID>`), nimmt er trotzdem `restic`, denn der Subaccount sieht nur sein eigenes Verzeichnis. Vor allem anderen prüft er die Anmeldung und meldet ein falsches Passwort oder eine nicht erreichbare Storage Box mit einem klaren Hinweis. Der Installer zeigt dann die letzten zehn Sicherungen des Panels mit Zeit (UTC) und ID, die neueste oben als Nummer 1 und Vorgabe. Mit Enter nimmt er die neueste, mit einer anderen Nummer eine ältere, etwa wenn die neueste schon einen Fehler enthält. Danach fragt er, ob er auch die Websites dieses Servers aus ihren Sicherungen neu aufbauen soll (`Rebuild the websites of this server from their backups? [Y/n]`, Vorgabe Ja). Er holt die gewählte Sicherung sofort; ein falsches Passwort fällt also auf, bevor etwas installiert ist. Die Zusammenfassung vor dem Start und die Meldung am Ende nennen ID und Zeit der Sicherung. Im Panel steht danach unter System → Backup, wann es aus welcher Sicherung wiederhergestellt wurde, und in der Liste der Panel-Sicherungen unter Server → Backups trägt diese Sicherung das Zeichen „wiederhergestellt“. Den Hinweis unter System → Backup kannst du mit dem Kreuz ausblenden; das Zeichen in der Liste bleibt.

   Domain und Zeitzone übernimmt er aus der Sicherung, Konten legt er keine an. Wie bei jeder ersten Installation bringt er das System vorher und am Ende auf den neuesten Stand (alle Updates, nicht nur Sicherheitsupdates); Debians automatische Updates ruhen so lange. Braucht ein Update einen Neustart, etwa ein neuer Kernel, bietet er ihn am Ende an (Vorgabe Ja); er wartet dafür, bis die Aufgaben des Panels erledigt sind, und die automatischen Updates laufen erst nach dem Neustart wieder. Baut das Panel die Websites neu auf, fragt er stattdessen, ob er neu starten soll, sobald die Websites fertig sind. Dann wartet er ohne festes Zeitlimit, zeigt alle 30 Sekunden den Fortschritt und startet erst danach neu, auch wenn ein Schritt fehlgeschlagen ist; das meldet er vorher. Mit Enter hörst du auf zu warten; der Neuaufbau läuft im Panel weiter, und du startest den Server später unter Server → Updates neu. Danach installiert er wie gewohnt und spielt dabei Datenbank, `.env` und den SSH-Schlüssel des Panels zurück. Beim eigenen Server trägt er den neuen Host-Schlüssel und SSH-Port ein.

4. **Anmelden** mit deinem alten Konto und deiner alten Zwei-Faktor-App. Die Sicherungen des Panel-Servers verbinden sich am Ende der Installation von selbst neu: Der neue Server bekommt einen eigenen Schlüssel für seinen alten Subaccount, und die täglichen Sicherungen laufen wieder. Unter Server → Backups steht danach „verbunden“. Klappt das nicht, meldet der Installer es; dann dort „Speichern und verbinden“ klicken.

5. **Websites des Panel-Servers zurückholen**, falls dort Websites lagen. Hast du die Frage des Installers mit Ja beantwortet, geschieht das von selbst, sobald die Sicherungen verbunden sind: Das Panel prüft, welche Websites dem Server fehlen, setzt zuerst die eigenen Firewall-Regeln und fail2ban-Ausnahmen wieder und baut dann jede fehlende Website in diesen Schritten auf:

   1. Website anlegen (Benutzer, Verzeichnis, NGINX, PHP-FPM), zunächst ohne Zertifikat
   2. ihre Datenbanken mit den gespeicherten Passwörtern anlegen
   3. Dateien und Datenbanken aus der neuesten automatischen oder von Hand gemachten Sicherung einspielen
   4. Cronjobs und SFTP-Zugang wieder einrichten
   5. zuletzt das Zertifikat einrichten: Seit 0.6 liegen die Zertifikate in der Sicherung des Panels, und der Installer legt sie zurück; ein gültiges bleibt, nur ein fälliges oder fehlendes wird neu angefordert, wofür der DNS-Eintrag der Website auf den neuen Server zeigen muss (E-199)

   Jeder Schritt ist eine eigene Aufgabe mit Protokoll unter Server → Aufgaben. Schlägt einer fehl, werden die folgenden Schritte dieser Website übersprungen; die anderen Websites laufen weiter. Gibt es für eine Website noch keine Sicherung, legt das Panel sie leer an.

   Hast du mit Nein geantwortet oder ist etwas fehlgeschlagen, zeigt Server → Backups die Karte „Fehlende Websites“ mit dem Knopf „Aus Sicherungen neu aufbauen“. Für eine einzelne Website gibt es unter Website → Backups „Aus neuester Sicherung neu aufbauen“, und auf der Seite jeder Sicherung „Aus dieser Sicherung neu aufbauen“ für eine ältere. Diese Knöpfe erscheinen nur, wenn die Website dem Server wirklich fehlt; eine vorhandene Website stellst du wie gewohnt wieder her.

   Die übrigen Server sind von all dem nicht betroffen und arbeiten mit dem zurückgespielten SSH-Schlüssel weiter.

## Hinweise

- Hat der neue Server einen anderen SSH-Port als der alte, übernimmt der Installer den neuen Port automatisch.
- Die Frage nach der Sicherung kommt nur bei einer ersten Installation in einem Terminal. Läuft der Installer ohne Terminal, installiert er ein neues Panel.
- Mit dem neuen `APP_KEY` aus der Sicherung werden alle Sitzungen ungültig. Jeder muss sich neu anmelden.
- Logs und das Verzeichnis `tmp` der Websites werden nicht gesichert und beginnen nach dem Neuaufbau leer.
- Ein Hetzner-Snapshot des alten Servers ist oft der schnellere Weg, wenn er noch existiert. Diese Anleitung ist für den Fall gedacht, dass Server oder Projekt verloren sind.
