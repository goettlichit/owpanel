<?php

/*
 * Turns the output of `restic ls --json` from stdin into a short listing
 * for the panel (E-098): the direct entries of one directory of a
 * snapshot, directories first, at most 500. Argument: the directory, or
 * "--files" for every file of the snapshot with its full path (E-099, the
 * few files of a panel backup). Reads nothing else.
 */

declare(strict_types=1);

$all = ($argv[1] ?? '') === '--files';
$dir = $all ? '' : rtrim((string) ($argv[1] ?? ''), '/');
$entries = [];
$more = false;
while (($line = fgets(STDIN)) !== false) {
    $node = json_decode($line, true);
    if (! is_array($node) || ($node['struct_type'] ?? null) !== 'node' || ! is_string($node['path'] ?? null) || ! is_string($node['name'] ?? null)) {
        continue;
    }
    if ($all ? ($node['type'] ?? null) !== 'file' : dirname($node['path']) !== $dir) {
        continue;
    }
    if ($node['name'] === '' || preg_match('/[\x00-\x1f\x7f]/', $node['name'])) {
        continue;
    }
    if (count($entries) >= 500) {
        $more = true;
        continue;
    }
    $type = in_array($node['type'] ?? null, ['dir', 'file', 'symlink'], true) ? $node['type'] : 'other';
    $entries[] = [
        'name' => $all ? $node['path'] : $node['name'],
        'type' => $type,
        'size' => $type === 'file' && is_int($node['size'] ?? null) ? $node['size'] : null,
        'mtime' => is_string($node['mtime'] ?? null) ? substr($node['mtime'], 0, 19) : null,
    ];
}
usort($entries, fn (array $a, array $b) => [$a['type'] !== 'dir', $a['name']] <=> [$b['type'] !== 'dir', $b['name']]);

echo json_encode(['entries' => $entries, 'more' => $more], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
