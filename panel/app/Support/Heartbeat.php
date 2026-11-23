<?php

namespace App\Support;

use App\Models\Setting;
use App\Workers\PanelWorkers;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Heartbeat to an outside monitoring such as Healthchecks, Uptime Kuma or
 * ipv64 (E-160). Every five minutes the panel calls the address, but only
 * while the scheduler and both workers answer. If the panel server, a
 * worker or the network fails, the calls stop and the monitoring reports
 * it; the panel cannot report its own failure.
 */
class Heartbeat
{
    public static function url(): ?string
    {
        $stored = Setting::get(Setting::HEARTBEAT_URL);
        if ($stored === null) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    public static function save(?string $url): void
    {
        Setting::put(Setting::HEARTBEAT_URL, $url === null ? null : Crypt::encryptString($url));
        Setting::put(Setting::HEARTBEAT_STATE, null);
    }

    /**
     * @return array{at: string, ok: bool, error: ?string, failing_since: ?string}|null
     */
    public static function state(): ?array
    {
        $state = json_decode((string) Setting::get(Setting::HEARTBEAT_STATE), true);

        return is_array($state) ? $state : null;
    }

    /**
     * Names of the panel services that do not answer; empty when all run.
     *
     * @return list<string>
     */
    public static function stalled(): array
    {
        $stalled = [];
        foreach (['scheduler', ...array_keys(PanelWorkers::QUEUES)] as $name) {
            $beat = PanelWorkers::lastBeat($name);
            if ($beat === null || $beat->lessThan(now()->subSeconds(PanelWorkers::STALE_AFTER))) {
                $stalled[] = $name;
            }
        }

        return $stalled;
    }

    /**
     * Calls the address once. Returns null on success, else the reason.
     */
    public static function ping(?string $url = null): ?string
    {
        $url ??= self::url();
        if ($url === null) {
            return null;
        }

        $checked = PublicUrl::check($url);
        if (! $checked['ok']) {
            $error = __('settings.heartbeat.refused.'.$checked['reason']);
        } else {
            try {
                $response = Http::timeout(10)->connectTimeout(5)->withOptions(PublicUrl::pinned($checked))
                    ->withUserAgent('OWPanel')->get($url);
                $error = $response->successful() ? null : __('settings.heartbeat.status', ['status' => $response->status()]);
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 200);
            }
        }

        $previous = self::state();
        Setting::put(Setting::HEARTBEAT_STATE, (string) json_encode([
            'at' => now()->toIso8601String(),
            'ok' => $error === null,
            'error' => $error,
            'failing_since' => $error === null ? null : ($previous['failing_since'] ?? now()->toIso8601String()),
        ]));

        return $error;
    }
}
