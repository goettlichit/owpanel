---
title: Anmeldung mit Pocket ID
description: Mit dem Passkey anmelden, über deine eigene Pocket-ID-Instanz.
group: Mehr
icon: fingerprint
slug: pocket-id
---

Neben Passwort und Zwei-Faktor kannst du dich am Panel mit einem Passkey anmelden. Dafür nutzt OWPanel [Pocket ID](https://pocket-id.org), einen schlanken Anmeldedienst, den du selbst betreibst. Jeder andere Anbieter mit OpenID Connect funktioniert genauso.

Pocket ID ist ein zusätzlicher Weg, kein Ersatz: Passwort und Zwei-Faktor bleiben für jedes Konto erhalten. Ist Pocket ID einmal nicht erreichbar, meldest du dich wie gewohnt mit Passwort an.

## Pocket ID vorbereiten

1. Lege in Pocket ID unter **OIDC-Clients** einen neuen Client an, etwa „OWPanel“.
2. Trage als **Callback-URL** die Adresse ein, die das Panel unter **System → Pocket ID** anzeigt. Sie hat die Form `https://panel.example.de/auth/oidc/callback`.
3. Schalte **PKCE** ein.
4. Notiere **Client-ID** und **Client-Secret**.

::: tip
Beschränkst du den Client in Pocket ID auf bestimmte Benutzergruppen, müssen auch Kunden, die Pocket ID nutzen sollen, in einer dieser Gruppen sein. Sonst lehnt Pocket ID die Anmeldung schon ab.
:::

## Im Panel einrichten

Unter **System → Pocket ID** findest du die Einstellungen:

| Feld | Inhalt |
|---|---|
| Name der Pocket-ID-Instanz | Wie der Dienst im Panel heißt, etwa „goettlichIT ID“. Er erscheint auf der Anmeldeseite als „Anmelden mit …“. Leer bedeutet „Pocket ID“. |
| Adresse der Instanz | Die Adresse deiner Pocket-ID-Instanz mit `https://`, etwa `https://login.example.de` |
| Client-ID und Client-Secret | Aus dem OIDC-Client in Pocket ID. Das Secret wird verschlüsselt gespeichert und nie wieder angezeigt. |

Beim **Speichern** prüft das Panel die Verbindung: Die Instanz muss sich unter genau dieser Adresse melden, nur `https` nutzen, PKCE anbieten und ihre Anmeldungen signieren. Passt etwas nicht, sagt das Panel, was fehlt, und speichert nichts. **Verbindung prüfen** wiederholt die Prüfung später jederzeit.

Ist die Karte grün, ist die Anmeldung eingerichtet. Um sie abzuschalten, leerst du die Adresse und speicherst; verknüpfte Konten bleiben verknüpft, solange die Adresse später gleich bleibt.

## Konto verknüpfen

Jedes Konto verknüpft sich einmal selbst, niemand kann ein fremdes Konto verknüpfen:

1. Mit Passwort am Panel anmelden.
2. **Mein Konto → Anmeldung und Sicherheit** öffnen.
3. **Mit … verknüpfen** wählen und dich bei Pocket ID mit deinem Passkey anmelden.

Ab dann genügt auf der Anmeldeseite **Anmelden mit …**. Auf derselben Seite trennst du die Verknüpfung wieder. Bei der Anmeldung ordnet das Panel ein Pocket-ID-Konto nur über diese Verknüpfung zu, nie über die E-Mail-Adresse.

Administratoren werden nach einer Anmeldung über Pocket ID nicht zusätzlich nach dem Zwei-Faktor-Code des Panels gefragt, denn der Passkey ist bereits ein starker zweiter Faktor.

## Pocket ID für Kunden

Administratoren dürfen Pocket ID immer nutzen. Kunden erst, wenn du es freischaltest: unter **Kunden → Kunde → Zugang** in der Karte mit dem Namen deiner Instanz über **… freischalten**. **… entziehen** nimmt die Freischaltung zurück und entfernt eine bestehende Verknüpfung.

Ohne weitere Einrichtung verknüpft der Kunde sein Konto danach selbst, wie oben beschrieben.

Mit einem **API-Schlüssel von Pocket ID** unter **System → Externe Dienste** erledigt das Panel das für dich:

1. Lege in Pocket ID unter **Einstellungen → Konto** einen API-Schlüssel nur mit den Rechten `users:read`, `users:write` und `users:login-codes` an und gib ihm ein Ablaufdatum. Rechte für API-Schlüssel gibt es ab Pocket ID 2.19; bei älteren Fassungen darf ein Schlüssel alles.
2. Trag den Schlüssel im Panel ein. Das Panel prüft ihn und speichert ihn verschlüsselt.
3. Auf der Seite **Zugang** des Kunden: **In … suchen**. Gibt es dort ein Konto mit seiner E-Mail-Adresse, zeigt das Panel es dir, und du verknüpfst es mit **Dieses Konto verknüpfen**. Gibt es keines, legt **In … anlegen und verknüpfen** das Konto an und verknüpft es sofort.
4. **Anmeldelink anzeigen** erzeugt einen Link, mit dem der Kunde seinen Passkey einrichtet (gilt 72 Stunden und einmal). **Anmeldelink per E-Mail senden** lässt Pocket ID den Link selbst schicken; dafür muss in Pocket ID der E-Mail-Versand eingerichtet sein.

Schneller geht es gleich beim Anlegen des Zugangs: Mit **Auch in … anlegen und Anmeldelink senden** legt das Panel den Kunden in Pocket ID an, verknüpft ihn und lässt Pocket ID den Anmeldelink schicken. Gibt es in Pocket ID schon ein Konto mit seiner Adresse, verknüpft das Panel es nicht von selbst; dann suchst du es wie oben und bestätigst es.

**Verknüpfung lösen** entfernt nur die Verknüpfung im Panel; das Konto in Pocket ID bleibt.

## Was im Audit-Log steht

Anmeldungen über Pocket ID, fehlgeschlagene Versuche, Verknüpfen und Trennen, Freischalten und Sperren sowie jede Änderung der Einstellungen stehen im Audit-Log unter **System → Audit-Log**.
