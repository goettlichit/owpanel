---
title: Statistik
description: Besucher und Seitenaufrufe der Webseiten, gezählt aus den Logs.
group: Mehr
icon: chart-bar
slug: statistics
---

Unter **System → Statistik** siehst du, wie viele Besucher deine Webseiten haben. Das Panel zählt dafür einmal in der Stunde auf jedem Server das Access-Log jeder Webseite aus. Auf den Webseiten selbst ändert sich nichts: kein Skript, keine Cookies, kein Hinweis im Cookie-Banner.

## Was du siehst

- **Webseiten:** alle Webseiten mit den Besuchern von heute, der letzten 7 und der letzten 30 Tage und den Seitenaufrufen der letzten 30 Tage, die meistbesuchte oben.
- **Diagramm:** Besucher je Tag für 7, 30 oder 90 Tage oder je Monat für 14 Monate, für alle Webseiten zusammen oder für eine. Mit der Maus auf einem Balken siehst du auch die Seitenaufrufe.
- **Meistbesuchte Seiten:** Wählst du eine Webseite aus, stehen darunter ihre zehn meistbesuchten Seiten im gewählten Zeitraum.

Die Statistik sehen vorerst nur Administratoren.

## Wie gezählt wird

- **Besucher:** eine IP-Adresse mit einem Browser an einem Tag. Über mehrere Tage werden die Tage zusammengezählt; wer an zwei Tagen kommt, zählt zweimal.
- **Seitenaufrufe:** nur erfolgreich ausgelieferte Seiten. Nicht mitgezählt werden Bilder, Stylesheets, Skripte und andere Dateien, Fehler wie 404, Weiterleitungen, Formular-Absendungen und erkennbare Bots wie Suchmaschinen oder Monitoring.
- Beim ersten Mal zählt das Panel bis zu 14 Tage zurück, so weit die Logs auf dem Server reichen. Danach zählt es jede Stunde den heutigen und den gestrigen Tag neu.

Die Werte sind Näherungswerte, wie bei jeder Auswertung aus Logs: Mehrere Personen hinter einem Router mit demselben Browser zählen als ein Besucher, ein Bot mit gewöhnlicher Browserkennung zählt mit.

## Datenschutz

Gezählt wird auf dem Server. Dabei werden IP-Adresse und Browser nur zum Zählen unkenntlich gemacht, mit einem Schlüssel, der nach dem Lauf verworfen wird. Ins Panel kommen nur Zahlen je Tag und die meistbesuchten Pfade, keine Adressen. Die Zahlen bleiben 14 Monate gespeichert. Die Access-Logs selbst löscht der Server wie bisher nach 14 Tagen.
