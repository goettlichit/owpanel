<?php

namespace App\Support;

/**
 * The last lines of a log file of the panel itself (E-087), read from the
 * end so that a large log costs no time. Lines over 4 KB are cut, bytes
 * that are not UTF-8 are replaced.
 */
final class LogTail
{
    private const CHUNK_BYTES = 65536;

    /**
     * The most bytes read from the end, whatever the number of lines.
     */
    private const MAX_BYTES = 4 * 1024 * 1024;

    private const MAX_LINE_BYTES = 4096;

    /**
     * @return array{lines: list<string>, size: int|null, modified: int|null}|array{error: string}
     */
    public static function read(string $path, int $lines): array
    {
        if (! is_file($path)) {
            return ['lines' => [], 'size' => null, 'modified' => null];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['error' => 'log_unreadable'];
        }

        try {
            $size = (int) filesize($path);
            $position = $size;
            $buffer = '';
            while ($position > 0 && substr_count($buffer, "\n") <= $lines && $size - $position < self::MAX_BYTES) {
                $read = min(self::CHUNK_BYTES, $position);
                $position -= $read;
                fseek($handle, $position);
                $buffer = fread($handle, $read).$buffer;
            }
        } finally {
            fclose($handle);
        }

        $all = preg_split('/\r?\n/', rtrim($buffer, "\r\n")) ?: [];
        if ($position > 0) {
            // The first line is probably cut off.
            array_shift($all);
        }

        $result = array_map(function (string $line): string {
            $line = mb_scrub($line, 'UTF-8');

            return strlen($line) > self::MAX_LINE_BYTES ? mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8').' …' : $line;
        }, array_slice($all, -$lines));

        return ['lines' => $buffer === '' ? [] : array_values($result), 'size' => $size, 'modified' => (int) filemtime($path)];
    }
}
