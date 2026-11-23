<?php

// System -> Statistics (E-148). Administrators only.
return [
    'title' => 'Statistics',
    'site' => 'Website',
    'all_sites' => 'All websites',
    'show' => 'Show',
    'range' => 'Period',
    'ranges' => [
        '7' => '7 days',
        '30' => '30 days',
        '90' => '90 days',
        'months' => '14 months',
    ],
    'visitors' => 'Visitors',
    'pageviews' => 'Page views',
    'page' => 'Page',
    'pages_title' => 'Most visited pages',
    'no_pages' => 'No page views in this period yet.',
    'sites_title' => 'Websites',
    'no_sites' => 'No websites yet.',
    'no_data' => 'No numbers yet',
    'columns' => [
        'today' => 'Visitors today',
        'week' => '7 days',
        'month' => '30 days',
        'pageviews' => 'Views 30 days',
    ],
    'counted' => 'Last counted :time. Counting runs every hour.',
    'not_counted' => 'Not counted yet. Counting runs every hour, the first time 14 days back.',
    'about_title' => 'How it is counted',
    'about' => [
        'Counted on the server from the access log of each website, without a script or cookies on the website.',
        'A visitor is one IP address with one browser on one day. Over several days the days are added up; whoever comes on two days counts twice.',
        'Page views count only successful pages: no images, stylesheets or scripts, no errors, redirects or form submissions and no recognisable bots.',
        'The panel stores only numbers per day and the most visited paths, no IP addresses. The numbers are kept for 14 months.',
        'The values are estimates, as with any evaluation of logs.',
    ],
];
