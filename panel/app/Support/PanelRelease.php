<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The installed version of the panel and the newest published one (E-154).
 * The newest comes from the releases of the source repository on GitHub,
 * checked once a day and on request; PanelUpdate installs it (E-185). Test
 * builds ("dev-…") are not compared.
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
     * published_at is when GitHub published the newest release; automatic
     * updates wait a day after it (E-185).
     *
     * @return array{version: string|null, published_at: string|null, checked_at: string|null, error: string|null}
     */
    public static function latest(): array
    {
        $data = json_decode((string) Setting::get(Setting::PANEL_RELEASE), true);

        return [
            'version' => is_string($data['version'] ?? null) ? $data['version'] : null,
            'published_at' => is_string($data['published_at'] ?? null) ? $data['published_at'] : null,
            'checked_at' => is_string($data['checked_at'] ?? null) ? $data['checked_at'] : null,
            'error' => is_string($data['error'] ?? null) ? $data['error'] : null,
        ];
    }

    /**
     * Whether $version only fixes $installed: same major and minor version,
     * neither a pre-release (E-185).
     */
    public static function isPatchOf(string $version, string $installed): bool
    {
        $pattern = '/^(\d+)\.(\d+)\.(\d+)$/';
        if (! preg_match($pattern, $version, $new) || ! preg_match($pattern, $installed, $old)) {
            return false;
        }

        return $new[1] === $old[1] && $new[2] === $old[2] && (int) $new[3] > (int) $old[3];
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
        $published = null;
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
                    $date = $response->json('published_at');
                    $published = is_string($date) && strtotime($date) !== false ? $date : null;
                } else {
                    $error = $response->status() === 404 ? 'not_found' : 'unreachable';
                }
            } catch (Throwable) {
                $error = 'unreachable';
            }
        }

        $previous = self::latest();
        Setting::put(Setting::PANEL_RELEASE, (string) json_encode([
            // Keep the last known version when a check fails.
            'version' => $version ?? $previous['version'],
            'published_at' => $version !== null ? $published : $previous['published_at'],
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
