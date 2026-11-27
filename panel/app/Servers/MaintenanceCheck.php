<?php

namespace App\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Notify\Events;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Runs server-updates and site-certificates on a server and stores the
 * answers (E-076). Called by owpanel:check-servers about once an hour per
 * server, or at the next minute after "Check now". Like StatusCheck this is
 * not a server task: it only reads.
 */
class MaintenanceCheck
{
    /**
     * Minutes between two checks of the same server.
     */
    public const INTERVAL_MINUTES = 60;

    private const PACKAGE = '/^[a-z0-9][a-z0-9.+-]{0,127}$/';

    private const VERSION = '/^[A-Za-z0-9.+~:-]{0,127}$/';

    public function __construct(private readonly ScriptRunner $runner) {}

    public static function isDue(Server $server): bool
    {
        return $server->updates_checked_at === null
            || $server->updates_checked_at->lessThan(now()->subMinutes(self::INTERVAL_MINUTES));
    }

    public function run(Server $server): void
    {
        $updates = $this->call($server, ScriptCall::serverUpdates());
        $error = is_string($updates) ? $updates : null;
        $parsed = is_array($updates) ? $this->parseUpdates($updates) : null;
        if (is_array($updates) && $parsed === null) {
            $error = 'invalid_result';
        }

        // A newer entry in the update history: packages were installed since
        // the last check (E-104). Not on the first check of a server.
        $previous = $server->updates['runs'][0]['at'] ?? null;
        $newest = $parsed['runs'][0] ?? null;
        $installed = $parsed !== null && is_array($server->updates) && $newest !== null && ($newest['count'] ?? 0) > 0
            && ($previous === null || strcmp((string) $newest['at'], (string) $previous) > 0);

        // Since when updates wait (E-159): kept while some are pending, so
        // updates the night does not install lead to a message after a day.
        if ($parsed !== null) {
            $parsed['pending_since'] = $parsed['pending'] > 0
                ? (($server->updates['pending'] ?? 0) > 0 && is_string($server->updates['pending_since'] ?? null)
                    ? $server->updates['pending_since']
                    : now()->toIso8601String())
                : null;
        }

        $server->forceFill([
            'updates' => $parsed ?? $server->updates,
            'updates_checked_at' => now(),
            'updates_error' => $error,
        ])->save();

        if ($installed) {
            Events::updatesInstalled($server, $newest);
        }

        if ($error !== null) {
            Log::warning('Server update check failed.', ['server' => $server->getKey(), 'error' => $error]);
        }

        $certificates = $this->call($server, ScriptCall::siteCertificates());
        if (is_array($certificates)) {
            $this->storeCertificates($server, $certificates);
        }
    }

    /**
     * @return array<string, mixed>|string The JSON answer, or an error code.
     */
    private function call(Server $server, ScriptCall $call): array|string
    {
        try {
            $result = $this->runner->run($server, $call, static function (): void {});
        } catch (ServerConnectionException $e) {
            return $e->errorCode;
        }

        if (! $result->succeeded()) {
            $error = $result->error() ?? 'invalid_result';

            return preg_match('/^[a-z0-9_]{1,50}$/', $error) ? $error : 'invalid_result';
        }

        return $result->json ?? 'invalid_result';
    }

    /**
     * Keeps only what has the expected shape; the server is not trusted.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function parseUpdates(array $json): ?array
    {
        $count = fn (mixed $value) => is_int($value) && $value >= 0 ? $value : null;
        $time = fn (mixed $value) => is_int($value) && $value > 0 ? $value : null;
        $text = fn (mixed $value, string $pattern) => is_string($value) && preg_match($pattern, $value) ? $value : null;

        $pending = $count($json['pending'] ?? null);
        $security = $count($json['security'] ?? null);
        if ($pending === null || $security === null || ! is_array($json['packages'] ?? null) || ! is_array($json['runs'] ?? null)) {
            return null;
        }

        $packages = [];
        foreach (array_slice($json['packages'], 0, 200) as $package) {
            $name = $text($package['name'] ?? null, self::PACKAGE);
            $to = $text($package['to'] ?? null, self::VERSION);
            if ($name === null || $to === null) {
                continue;
            }
            $packages[] = [
                'name' => $name,
                'from' => $text($package['from'] ?? null, self::VERSION) ?? '',
                'to' => $to,
                'security' => ($package['security'] ?? false) === true,
            ];
        }

        $runs = [];
        foreach (array_slice($json['runs'], 0, 10) as $run) {
            $at = $time($run['at'] ?? null);
            $kind = in_array($run['kind'] ?? null, ['automatic', 'manual'], true) ? $run['kind'] : null;
            if ($at === null || $kind === null) {
                continue;
            }
            $runs[] = [
                'at' => $at,
                'kind' => $kind,
                'count' => $count($run['count'] ?? null) ?? 0,
                'packages' => array_values(array_filter(
                    array_slice(is_array($run['packages'] ?? null) ? $run['packages'] : [], 0, 40),
                    fn ($name) => $text($name, self::PACKAGE) !== null,
                )),
            ];
        }

        $reboot = is_array($json['reboot'] ?? null) ? $json['reboot'] : [];

        return [
            'pending' => $pending,
            'security' => min($security, $pending),
            'packages' => $packages,
            'runs' => $runs,
            'lists_updated' => $time($json['lists_updated'] ?? null),
            'upgraded' => $time($json['upgraded'] ?? null),
            'automatic' => ($json['automatic'] ?? false) === true,
            'reboot' => [
                'required' => ($reboot['required'] ?? false) === true,
                'kernel_running' => $text($reboot['kernel_running'] ?? null, '/^[A-Za-z0-9._+-]{1,64}$/'),
                'kernel_installed' => $text($reboot['kernel_installed'] ?? null, '/^[A-Za-z0-9._+-]{1,64}$/'),
                'packages' => array_values(array_filter(
                    array_slice(is_array($reboot['packages'] ?? null) ? $reboot['packages'] : [], 0, 20),
                    fn ($name) => $text($name, self::PACKAGE) !== null,
                )),
            ],
        ];
    }

    /**
     * Expiry per site user; ow10000 is the panel itself.
     *
     * @param  array<string, mixed>  $json
     */
    private function storeCertificates(Server $server, array $json): void
    {
        $expiry = [];
        foreach (is_array($json['certificates'] ?? null) ? $json['certificates'] : [] as $certificate) {
            $user = $certificate['user'] ?? null;
            $expires = $certificate['expires'] ?? null;
            if (is_string($user) && preg_match('/^ow[1-5][0-9]{4}$/', $user) && is_int($expires) && $expires > 0) {
                $expiry[$user] = Carbon::createFromTimestamp($expires);
            }
        }

        $server->forceFill([
            'certificate_renewal' => ($json['renewal_timer'] ?? false) === true,
            'panel_certificate_expires_at' => $expiry['ow10000'] ?? null,
        ])->save();

        // The certificates of the websites live at their gateway (E-195).
        $server->gatewaySites()?->each(function (Site $site) use ($expiry) {
            $site->forceFill(['certificate_expires_at' => $expiry[$site->system_user] ?? null])->save();
        });
    }
}
