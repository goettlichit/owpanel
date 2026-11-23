<?php

/*
 * Turns `restic snapshots --json` from stdin into a short list for the
 * panel (E-096): id, time, size and kind (schedule, manual or restore), newest first, at most 200. Reads
 * nothing else.
 */

declare(strict_types=1);

$data = json_decode((string) stream_get_contents(STDIN), true);
$list = [];
foreach (is_array($data) ? $data : [] as $snapshot) {
    if (! is_array($snapshot) || ! is_string($snapshot['id'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $snapshot['id'])) {
        continue;
    }
    $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
    $tags = is_array($snapshot['tags'] ?? null) ? $snapshot['tags'] : [];
    $list[] = [
        'id' => substr($snapshot['id'], 0, 8),
        'time' => is_string($snapshot['time'] ?? null) ? $snapshot['time'] : null,
        'size' => isset($summary['total_bytes_processed']) ? (int) $summary['total_bytes_processed'] : null,
        'added' => isset($summary['data_added']) ? (int) $summary['data_added'] : null,
        'kind' => match (true) {
            in_array('restore', $tags, true) => 'restore',
            in_array('manual', $tags, true) => 'manual',
            default => 'schedule',
        },
    ];
}
usort($list, fn (array $a, array $b) => strcmp((string) $b['time'], (string) $a['time']));

echo json_encode(array_slice($list, 0, 200), JSON_UNESCAPED_SLASHES);
