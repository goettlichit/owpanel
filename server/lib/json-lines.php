<?php

/*
 * Turns text lines from stdin into a JSON list for result lines, e.g. the
 * log lines of site-logs (E-086). Bytes that are not UTF-8 are replaced,
 * long lines are cut. Only encodes; it reads nothing else.
 */

declare(strict_types=1);

const MAX_LINE_BYTES = 4096;

$lines = [];
while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\r\n");
    if (strlen($line) > MAX_LINE_BYTES) {
        $line = substr($line, 0, MAX_LINE_BYTES).' …';
    }
    $lines[] = $line;
}

echo json_encode($lines, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
