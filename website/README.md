# Website und Dokumentation

Die Projekt-Website von OWPanel mit Vorstellung und Dokumentation auf Deutsch und Englisch (E-109). Sie ist eine statische Website im Design des Panels (`docs/DESIGN.md`): Tabler, Inter und die Icons liegen bei, es gibt keine CDNs und keine Cookies.

## Aufbau

| Pfad | Inhalt |
|---|---|
| `content/<Sprache>/site.json` | Texte der Startseite, der Navigation und der Fußzeile |
| `content/<Sprache>/docs/*.md` | Seiten der Dokumentation, mit Titel, Beschreibung, Gruppe und Icon im Kopf |
| `src/` | Stile, Skripte und Favicon |
| `site.json` | Einstellungen: Adresse der Website, Quellcode, Impressum und Datenschutz |
| `build.mjs` | Baut alles nach `dist/` |

Seiten für Kunden tragen im Kopf `audience: customer` und sind in der Sie-Form geschrieben wie der Kundenbereich (E-132); ohne Angabe gilt eine Seite für Administratoren. Die Seitenleiste zeigt zuerst die Seiten für Administratoren nach Gruppen, dann die für Kunden (E-178). Beide Sprachen brauchen dieselben Seiten mit demselben `slug`; sonst bricht der Build ab. Links auf andere Seiten der Dokumentation schreibst du als `[Text](docs:installation)` oder `[Text](docs:backups#notfalldaten)`. Hinweisboxen stehen zwischen `::: info` (oder `tip`, `warning`, `danger`) und `:::`.

## Bauen

```sh
cd website
npm ci
npm run build
```

Die Seiten liegen danach in `dist/`. Zum Ansehen reicht `python3 -m http.server -d dist 8000`.

Adresse, Impressum und Datenschutz stehen in `site.json`. Für eigene Testbuilds kannst du sie in `site.local.json` überschreiben. Die Datei liegt nicht im Repository:

```json
{
    "base_url": "http://localhost:8000"
}
```

## Veröffentlichen

Die Website läuft als gewöhnliche Website in OWPanel. Lege sie im Panel an, schalte SFTP ein und lade den Inhalt von `dist/` nach `htdocs/public/`:

```sh
scp -r dist/* ow12345@server.example:htdocs/public/
```

`ow12345` ist der Linux-Benutzer der Website. GitHub Actions baut die Website bei jeder Änderung und legt `dist/` als Artefakt `owpanel-website` ab.
