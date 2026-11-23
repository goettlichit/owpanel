<?php

// Storage of websites and customers (E-126). Shared by the administration
// and the customer area, so without form of address (E-132).
return [
    'title' => 'Speicherplatz',
    'not_measured' => 'Noch nicht gemessen. Gemessen wird alle 15 Minuten.',
    'used_of' => ':used von :limit belegt',
    'used_unlimited' => ':used belegt, ohne Grenze',
    'free' => ':free frei',
    'split' => 'Dateien :files, Datenbanken :databases',
    'measured' => 'Stand :time Uhr',
    'files' => 'Dateien',
    'databases' => 'Datenbanken',
    'column' => 'Belegt',
    'quota_unavailable' => 'Auf diesem Server sind keine Quotas aktiv (:filesystem). Die Grenze wird angezeigt, aber nicht vom Dateisystem durchgesetzt.',
    'quota_unknown' => 'unbekanntes Dateisystem',
];
