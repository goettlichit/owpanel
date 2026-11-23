<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The installed version of the panel and the newest published one (E-154).
 * The newest comes from the releases of the source repository on GitHub,
 * checked once a day and on request; installing it follows in milestone 8
 * (F-012). Test builds ("dev-…") are not compared.
 */
class PanelRelease
{
    public static function installed(): string
    {
        return (string) config('owpanel.version');
    }

    public static function isTestBuild(): bool
    {
        return ! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', self::installed());
    }

    /**
     * @return array{version: string|null, checked_at: string|null, error: string|null}
     */
    public static function latest(): array
    {
        $data = json_decode((string) Setting::get(Setting::PANEL_RELEASE), true);

        return [
            'version' => is_string($data['version'] ?? null) ? $data['version'] : null,
            'checked_at' => is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null,
            'error' => is_string($data['error'] ?? null) ? $data['error'] : null,
        ];
    }

    public static function updateAvailable(): bool
    {
        $latest = self::latest()['version'];

        return $latest !== null && ! self::isTestBuild() && version_compare($latest, self::installed(), '>');
    }

    /**
     * Asks GitHub for the newest release. A private repository answers 404:
     * then nothing can be compared yet.
     */
    public static function check(): void
    {
        $version = null;
        $error = null;
        $repository = self::repository();
        if ($repository === null) {
            $error = 'no_repository';
        } else {
            try {
                $response = Http::acceptJson()->timeout(15)
                    ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                    ->get('https://api.github.com/repos/'.$repository.'/releases/latest');
                $tag = $response->successful() ? $response->json('tag_name') : null;
                if (is_string($tag) && preg_match('/^v?(\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?)$/', $tag, $match)) {
                    $version = $match[1];
                } else {
                    $error = $response->status() === 404 ? 'not_found' : 'unreachable';
                }
            } catch (Throwable) {
                $error = 'unreachable';
            }
        }

        Setting::put(Setting::PANEL_RELEASE, (string) json_encode([
            // Keep the last known version when a check fails.
            'version' => $version ?? self::latest()['version'],
            'checked_at' => now()->toIso8601String(),
            'error' => $error,
        ]));
    }

    /**
     * "owner/name" from the source URL, only for github.com.
     */
    public static function repository(): ?string
    {
        $url = (string) config('owpanel.source_url');

        return preg_match('#^https://github\.com/([A-Za-z0-9-]+/[A-Za-z0-9._-]+?)(\.git)?/?$#', $url, $match) === 1 ? $match[1] : null;
    }
}
