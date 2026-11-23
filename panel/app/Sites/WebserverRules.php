<?php

namespace App\Sites;

use App\Models\Site;

/**
 * Own nginx rules of a website (E-184), for administrators only: extra
 * response headers and blocked paths. There is no free nginx text; each
 * rule is checked here and again by server/bin/site-nginx with the same
 * patterns (server/lib/site-rules.sh), and the script writes it in a fixed
 * form.
 *
 * The page edits both lists as text, one rule per line: headers as
 * "Name: value", paths as "/path" (ending in / for a whole folder).
 */
final class WebserverRules
{
    public const MAX_HEADERS = 20;

    public const MAX_PATHS = 50;

    public const MAX_VALUE = 2048;

    /**
     * Headers OWPanel sets itself and those that would break responses
     * (lower case). Same list as owp_rules_valid_header_name.
     */
    public const RESERVED_HEADERS = [
        'alt-svc', 'x-content-type-options', 'referrer-policy', 'x-frame-options',
        'connection', 'content-length', 'content-type', 'transfer-encoding', 'location',
        'set-cookie', 'keep-alive', 'upgrade', 'host', 'server', 'date',
    ];

    public static function validHeaderName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $name) === 1
            && ! in_array(strtolower($name), self::RESERVED_HEADERS, true);
    }

    /**
     * Printable ASCII without ", \, $, { and }, no space at either end.
     */
    public static function validHeaderValue(string $value): bool
    {
        return strlen($value) <= self::MAX_VALUE
            && preg_match('/^[\x20-\x7E]+$/', $value) === 1
            && preg_match('/["\\\\${}]/', $value) === 0
            && trim($value, ' ') === $value;
    }

    /**
     * Starts with /, only letters, digits and . _ ~ / -, no empty segment
     * and no segment starting with a dot; not / itself.
     */
    public static function validPath(string $path): bool
    {
        return preg_match('#^/[A-Za-z0-9._~/-]{1,200}$#', $path) === 1
            && ! str_contains($path, '//')
            && ! str_contains($path, '/.');
    }

    /**
     * Reads the header text of the form. Returns the headers or the errors,
     * one per bad line.
     *
     * @return array{0: list<array{name: string, value: string}>, 1: list<string>}
     */
    public static function parseHeaders(string $text): array
    {
        $headers = [];
        $errors = [];
        $seen = [];
        foreach (self::lines($text) as $number => $line) {
            if (! str_contains($line, ':')) {
                $errors[] = __('sites.webserver.errors.header_format', ['line' => $number]);

                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                $errors[] = __('sites.webserver.errors.header_reserved', ['line' => $number, 'name' => $name]);
            } elseif (! self::validHeaderName($name)) {
                $errors[] = __('sites.webserver.errors.header_name', ['line' => $number]);
            } elseif (! self::validHeaderValue($value)) {
                $errors[] = __('sites.webserver.errors.header_value', ['line' => $number]);
            } elseif (isset($seen[strtolower($name)])) {
                $errors[] = __('sites.webserver.errors.header_duplicate', ['line' => $number, 'name' => $name]);
            } else {
                $seen[strtolower($name)] = true;
                $headers[] = ['name' => $name, 'value' => $value];
            }
        }
        if (count($headers) > self::MAX_HEADERS) {
            $errors[] = __('sites.webserver.errors.too_many_headers', ['max' => self::MAX_HEADERS]);
        }

        return [$headers, $errors];
    }

    /**
     * Reads the path text of the form.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public static function parsePaths(string $text): array
    {
        $paths = [];
        $errors = [];
        foreach (self::lines($text) as $number => $path) {
            if (! self::validPath($path)) {
                $errors[] = __('sites.webserver.errors.path', ['line' => $number]);
            } elseif (in_array($path, $paths, true)) {
                $errors[] = __('sites.webserver.errors.path_duplicate', ['line' => $number, 'path' => $path]);
            } else {
                $paths[] = $path;
            }
        }
        if (count($paths) > self::MAX_PATHS) {
            $errors[] = __('sites.webserver.errors.too_many_paths', ['max' => self::MAX_PATHS]);
        }

        return [$paths, $errors];
    }

    /**
     * The headers of a website as text for the form.
     */
    public static function headersText(Site $site): string
    {
        return implode("\n", array_map(fn (array $h) => $h['name'].': '.$h['value'], $site->nginx_headers ?? []));
    }

    public static function pathsText(Site $site): string
    {
        return implode("\n", $site->nginx_blocked_paths ?? []);
    }

    public static function any(Site $site): bool
    {
        return ($site->nginx_headers ?? []) !== [] || ($site->nginx_blocked_paths ?? []) !== [];
    }

    /**
     * Non-empty lines without surrounding spaces, keyed by line number.
     *
     * @return array<int, string>
     */
    private static function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[$index + 1] = $line;
            }
        }

        return $lines;
    }
}
