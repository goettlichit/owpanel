<?php

namespace App\Firewall;

use App\Support\PublicUrl;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads a blocklist (E-170) through PublicUrl: only HTTPS, only public
 * addresses, pinned to the checked IP, no redirects, at most 16 MiB. The
 * size is watched with Guzzle's progress option, which stops the transfer
 * once it is larger: Guzzle refuses CURLOPT_MAXFILESIZE among its curl
 * options, and its stream option would switch to a handler that ignores
 * the pinned IP.
 */
final class BlocklistFetcher
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    /**
     * The entries, sorted, with a hash to see whether the list changed. The
     * error is one of format, https, resolve, internal, redirect, http,
     * too_large, connection or not_a_list; detail says more for connection
     * (the curl error) and http (the status).
     *
     * @return array{ok: true, entries: list<string>, ignored: int, invalid: int, hash: string}|array{ok: false, error: string, detail?: string}
     */
    public static function fetch(string $url): array
    {
        $checked = PublicUrl::check($url);
        if (! $checked['ok']) {
            return ['ok' => false, 'error' => $checked['reason']];
        }

        // Each checked address in turn, IPv4 first, like a browser that falls
        // back when one does not answer.
        $response = null;
        $detail = '';
        $tooLarge = false;
        foreach ($checked['ips'] as $ip) {
            $options = PublicUrl::pinned($checked, $ip) + [
                'progress' => function (int $expected, int $received) use (&$tooLarge): bool {
                    return $tooLarge = $expected > self::MAX_BYTES || $received > self::MAX_BYTES;
                },
            ];
            try {
                $response = Http::timeout(30)->connectTimeout(5)->withOptions($options)
                    ->withUserAgent('OWPanel/'.config('owpanel.version').' (+https://owpanel.org)')
                    ->withHeaders(['Accept' => 'text/plain, application/json;q=0.9, */*;q=0.5'])
                    ->get($url);
                break;
            } catch (Throwable $exception) {
                if ($tooLarge) {
                    return ['ok' => false, 'error' => 'too_large'];
                }
                $detail = self::curlError($exception->getMessage());
            }
        }
        if ($response === null) {
            return ['ok' => false, 'error' => 'connection', 'detail' => $detail];
        }
        if ($response->redirect()) {
            return ['ok' => false, 'error' => 'redirect'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'http', 'detail' => 'HTTP '.$response->status()];
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'too_large'];
        }

        $parsed = BlocklistParser::parse($body);
        if (! $parsed['ok']) {
            return ['ok' => false, 'error' => 'not_a_list'];
        }
        $entries = $parsed['entries'];
        sort($entries, SORT_STRING);

        return ['ok' => true, 'entries' => $entries, 'ignored' => $parsed['ignored'], 'invalid' => $parsed['invalid'], 'hash' => hash('sha256', implode("\n", $entries))];
    }

    /**
     * The curl error of a failed call, such as "cURL error 28: Connection
     * timed out after 5001 milliseconds", without the address and at most
     * 160 characters, so it can be shown to the administrator.
     */
    private static function curlError(string $message): string
    {
        if (preg_match('/cURL error \d+: [^(]*/', $message, $match) === 1) {
            $message = $match[0];
        }

        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, 160);
    }
}
