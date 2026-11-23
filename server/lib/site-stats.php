<?php

/*
 * Counts visitors and page views per day from a website's nginx access log
 * in the combined format, read from stdin (site-stats, E-148). Prints only
 * numbers and paths as JSON: no IP addresses, no user agents. A visitor is
 * one IP address with one user agent on one day; both are hashed with a key
 * that exists only during this run.
 *
 *   php site-stats.php <today YYYY-MM-DD> <days 1-14>
 */

declare(strict_types=1);

const TOP_PAGES = 10;
const MAX_PATH = 200;

// Files, not pages: they come with every page view.
const ASSET = '/\.(css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|mp3|pdf|zip|gz|xml|txt|json)$/i';

// Programs and services, not people. Missing agents count as programs.
const BOT = '/bot|crawl|spider|slurp|archiver|curl|wget|python|go-http|java\/|okhttp|libwww|httpclient|axios|node-fetch|scrapy|headless|phantom|monitor|uptime|pingdom|statuscake|check_http|nagios|zabbix|lighthouse|preview|facebookexternalhit|whatsapp|telegram|discord|slack|feed|rss|validator|scanner|nmap|masscan|zgrab|nikto/i';

// 16/Nov/2026:07:00:04 +0100 → 2026-11-16
const MONTHS = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06',
    'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];

[$today, $days] = [$argv[1] ?? '', (int) ($argv[2] ?? 0)];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1 || $days < 1 || $days > 14) {
    fwrite(STDERR, "usage: site-stats.php <YYYY-MM-DD> <1-14>\n");
    exit(2);
}
$first = (new DateTimeImmutable($today))->modify('-'.($days - 1).' days')->format('Y-m-d');
$key = random_bytes(32);

$stats = [];
while (($line = fgets(STDIN)) !== false) {
    if (preg_match('/^(\S+) \S+ \S+ \[(\d{2})\/(\w{3})\/(\d{4}):[^\]]*\] "(\S+) (\S+)[^"]*" (\d{3}) \S+ "(?:[^"\\\\]|\\\\.)*" "((?:[^"\\\\]|\\\\.)*)"/', $line, $m) !== 1) {
        continue;
    }
    [, $ip, $day, $month, $year, $method, $target, $status, $agent] = $m;
    $date = $year.'-'.(MONTHS[$month] ?? '00').'-'.$day;
    if ($date < $first || $date > $today) {
        continue;
    }
    $path = (string) (parse_url($target, PHP_URL_PATH) ?? '');
    // Redirects (301, 302) and errors are no page view; 304 is a page
    // the browser had cached.
    if (($method !== 'GET' && $method !== 'HEAD') || ($status !== '200' && $status !== '304')
        || $agent === '' || $agent === '-' || preg_match(BOT, $agent) === 1
        || $path === '' || $path[0] !== '/' || preg_match(ASSET, $path) === 1) {
        continue;
    }
    $stats[$date] ??= ['visitors' => [], 'pageviews' => 0, 'pages' => []];
    $stats[$date]['visitors'][hash_hmac('sha256', $ip."\n".$agent, $key, true)] = true;
    $stats[$date]['pageviews']++;
    // Cut by bytes (php -n has no mbstring); a cut character becomes U+FFFD.
    $path = substr(rawurldecode($path), 0, MAX_PATH);
    $stats[$date]['pages'][$path] = ($stats[$date]['pages'][$path] ?? 0) + 1;
}

$result = [];
for ($date = $first; $date <= $today; $date = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')) {
    $day = $stats[$date] ?? ['visitors' => [], 'pageviews' => 0, 'pages' => []];
    arsort($day['pages']);
    $pages = [];
    foreach (array_slice($day['pages'], 0, TOP_PAGES, true) as $path => $count) {
        $pages[] = [(string) $path, $count];
    }
    $result[] = ['date' => $date, 'visitors' => count($day['visitors']), 'pageviews' => $day['pageviews'], 'pages' => $pages];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
