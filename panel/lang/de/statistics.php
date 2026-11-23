<?php

// System -> Statistics (E-148). Administrators only, addressed with "du".
return [
    'title' => 'Statistik',
    'site' => 'Webseite',
    'all_sites' => 'Alle Webseiten',
    'show' => 'Anzeigen',
    'range' => 'Zeitraum',
    'ranges' => [
        '7' => '7 Tage',
        '30' => '30 Tage',
        '90' => '90 Tage',
        'months' => '14 Monate',
    ],
    'visitors' => 'Besucher',
    'pageviews' => 'Seitenaufrufe',
    'page' => 'Seite',
    'pages_title' => 'Meistbesuchte Seiten',
    'no_pages' => 'In diesem Zeitraum noch keine Seitenaufrufe.',
    'sites_title' => 'Webseiten',
    'no_sites' => 'Noch keine Webseiten.',
    'no_data' => 'Noch keine Zahlen',
    'columns' => [
        'today' => 'Besucher heute',
        'week' => '7 Tage',
        'month' => '30 Tage',
        'pageviews' => 'Aufrufe 30 Tage',
    ],
    'counted' => 'Zuletzt gezählt :time. Gezählt wird jede Stunde.',
    'not_counted' => 'Noch nicht gezählt. Gezählt wird jede Stunde, beim ersten Mal 14 Tage rückwirkend.',
    'about_title' => 'Wie gezählt wird',
    'about' => [
        'Gezählt wird auf dem Server aus dem Access-Log jeder Webseite, ohne Skript und ohne Cookies auf der Webseite.',
        'Ein Besucher ist eine IP-Adresse mit einem Browser an einem Tag. Über mehrere Tage werden die Tage zusammengezählt; wer an zwei Tagen kommt, zählt zweimal.',
        'Seitenaufrufe zählen nur erfolgreiche Seiten, keine Bilder, Stylesheets oder Skripte, keine Fehler, Weiterleitungen oder Formular-Absendungen und keine erkennbaren Bots.',
        'Das Panel speichert nur Zahlen je Tag und die meistbesuchten Pfade, keine IP-Adressen. Die Zahlen bleiben 14 Monate.',
        'Die Werte sind Näherungswerte, wie bei jeder Auswertung aus Logs.',
    ],
];
