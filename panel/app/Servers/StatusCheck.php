<?php

namespace App\Servers;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Notify\Events;
use App\Support\ReleaseKeys;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs server-status on a server and stores the answer (E-075).
 *
 * This is not a server task: it runs every minute, changes nothing on the
 * server and would only flood the task list. A failed check marks the
 * server as offline with the error code, which the overview shows.
 */
class StatusCheck
{
    /** Release versions as in scripts/build-release and get-owpanel. */
    public const VERSION_PATTERN = '/^(\d{1,4}\.\d{1,4}\.\d{1,4}(-[0-9A-Za-z.]{1,32})?|dev-[0-9a-f]{7,40})$/';

    public function __construct(private readonly ScriptRunner $runner) {}

    public function run(Server $server): void
    {
        $now = now();

        try {
            $result = $this->runner->run($server, ScriptCall::serverStatus(), static function (): void {});
        } catch (ServerConnectionException $e) {
            $this->failed($server, $e->errorCode);

            return;
        }

        $data = $result->succeeded() ? $this->parse($result->json ?? []) : null;
        if ($data === null) {
            $this->failed($server, $result->error() ?? 'invalid_result');

            return;
        }

        // After a restart the update list (running kernel, last runs) is
        // read again with the next check instead of up to an hour later.
        $bootedAt = $now->copy()->subSeconds($data['uptime'])->startOfMinute();
        $restarted = $server->booted_at !== null && $bootedAt->greaterThan($server->booted_at->copy()->addMinutes(2));
        if ($restarted) {
            $server->updates_checked_at = null;
        }

        $server->forceFill([
            'hostname' => $data['hostname'],
            'os_name' => $data['os'],
            'kernel' => $data['kernel'],
            'cpu_cores' => $data['cpu_cores'],
            'booted_at' => $bootedAt,
            'reboot_required' => $data['reboot_required'],
            'present_sites' => $data['sites'],
            // An older server-status says nothing: keep what was known.
            'services' => $data['services'] ?? $server->services,
            // Null for older scripts and for scripts from a checkout (E-167).
            'scripts_version' => $data['scripts_version'],
            'release_keys' => $data['release_keys'],
            // An older server-status says nothing: keep what was known (E-172).
            'php_versions' => $data['php_versions'] ?? $server->php_versions,
            'checked_at' => $now,
            'last_seen_at' => $now,
            'check_error' => null,
        ])->save();
        // Scripts of another version are brought up to date (E-187).
        try {
            ScriptsUpdater::ensure($server);
        } catch (Throwable $e) {
            report($e);
        }
        if ($restarted) {
            Events::serverRestarted($server, $bootedAt);
        }

        $metric = new ServerMetric;
        $metric->forceFill([
            'server_id' => $server->getKey(),
            'recorded_at' => $now,
            'cpu_percent' => $data['cpu'],
            'load1' => $data['load'][0],
            'load5' => $data['load'][1],
            'load15' => $data['load'][2],
            'memory_total' => $data['memory']['total'],
            'memory_used' => $data['memory']['used'],
            'swap_total' => $data['swap']['total'],
            'swap_used' => $data['swap']['used'],
            'disk_total' => $data['disk']['total'],
            'disk_used' => $data['disk']['used'],
            'network_rx' => $data['network']['rx'],
            'network_tx' => $data['network']['tx'],
            'network_interfaces' => $data['interfaces'] ?: null,
        ])->save();
    }

    private function failed(Server $server, string $error): void
    {
        $error = preg_match('/^[a-z0-9_]{1,50}$/', $error) ? $error : 'invalid_result';

        // Logged only when the state changes, not once a minute.
        if ($server->check_error !== $error) {
            Log::warning('Server status check failed.', ['server' => $server->getKey(), 'error' => $error]);
        }

        $server->forceFill(['checked_at' => now(), 'check_error' => $error])->save();
    }

    /**
     * Network cards with their counters (E-111): at most eight, valid names,
     * each name once. Missing in answers of older scripts, then empty.
     *
     * @return array<string, array{rx: int, tx: int, public: bool}>|null
     */
    private function interfaces(mixed $list): ?array
    {
        if (! is_array($list) || ! array_is_list($list) || count($list) > 8) {
            return null;
        }

        $interfaces = [];
        foreach ($list as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            if (! is_string($name) || ! preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $name) || isset($interfaces[$name])
                || ! is_int($entry['rx'] ?? null) || $entry['rx'] < 0
                || ! is_int($entry['tx'] ?? null) || $entry['tx'] < 0
                || ! is_bool($entry['public'] ?? null)) {
                return null;
            }
            $interfaces[$name] = ['rx' => $entry['rx'], 'tx' => $entry['tx'], 'public' => $entry['public']];
        }

        return $interfaces;
    }

    /**
     * Checks every field of the answer; the server is not trusted to send
     * sensible values. Returns null if anything is missing or out of range.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>|null
     */
    private function parse(array $json): ?array
    {
        $count = fn (mixed $value) => is_int($value) && $value >= 0 ? $value : null;
        $pair = fn (mixed $value, string $a, string $b) => is_array($value) && $count($value[$a] ?? null) !== null && $count($value[$b] ?? null) !== null
            ? [$a => $value[$a], $b => $value[$b]]
            : null;

        $load = $json['load'] ?? null;
        $loadValid = is_array($load) && count($load) === 3
            && array_all($load, fn ($value) => (is_int($value) || is_float($value)) && $value >= 0 && $value < 100000);

        $cpu = $json['cpu'] ?? null;
        $data = [
            'hostname' => $this->text($json['hostname'] ?? null, '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/'),
            'os' => $this->text($json['os'] ?? null, '/^[A-Za-z0-9 ._\/()+-]{1,80}$/'),
            'kernel' => $this->text($json['kernel'] ?? null, '/^[A-Za-z0-9._+-]{1,64}$/'),
            'uptime' => $count($json['uptime'] ?? null),
            'cpu_cores' => $count($json['cpu_cores'] ?? null),
            'cpu' => (is_int($cpu) || is_float($cpu)) && $cpu >= 0 && $cpu <= 100 ? (float) $cpu : null,
            'load' => $loadValid ? array_map(fn ($value) => round((float) $value, 2), $load) : null,
            'memory' => $pair($json['memory'] ?? null, 'total', 'used'),
            'swap' => $pair($json['swap'] ?? null, 'total', 'used'),
            'disk' => $pair($json['disk'] ?? null, 'total', 'used'),
            'network' => $pair($json['network'] ?? null, 'rx', 'tx'),
            'interfaces' => $this->interfaces($json['network']['interfaces'] ?? []),
            'reboot_required' => ($json['reboot_required'] ?? null) === true,
            'sites' => $this->siteUsers($json['sites'] ?? null),
            'services' => $this->services($json['services'] ?? null),
            'scripts_version' => $this->text($json['scripts_version'] ?? null, self::VERSION_PATTERN),
            'release_keys' => $this->releaseKeys($json['release_keys'] ?? null),
            'php_versions' => $this->phpVersions($json['php_versions'] ?? null),
        ];

        foreach (['uptime', 'cpu_cores', 'cpu', 'load', 'memory', 'swap', 'disk', 'network', 'interfaces'] as $required) {
            if ($data[$required] === null) {
                return null;
            }
        }

        $data['cpu_cores'] = min($data['cpu_cores'], 65535);

        return $data;
    }

    /**
     * The watched services and whether they run (E-160), or null if the
     * server did not say.
     *
     * @return array<string, bool>|null
     */
    private function services(mixed $list): ?array
    {
        if (! is_array($list) || ! array_is_list($list)) {
            return null;
        }
        $services = [];
        foreach (array_slice($list, 0, 12) as $entry) {
            $name = $entry['name'] ?? null;
            if (is_string($name) && preg_match('/^(nginx|mariadb|fail2ban|nftables|php\d\.\d+-fpm)$/', $name) && is_bool($entry['active'] ?? null)) {
                $services[$name] = $entry['active'];
            }
        }
        ksort($services);

        return $services;
    }

    /**
     * The website users that exist on the server (E-106), or null if the
     * server did not say, e.g. with an older server-status.
     *
     * @return list<string>|null
     */
    private function siteUsers(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        return array_values(array_unique(array_filter(
            $value,
            fn (mixed $user) => is_string($user) && preg_match('/^ow[1-5][0-9]{4}$/', $user) === 1,
        )));
    }

    /**
     * Fingerprints of the release keys (E-167); null for older scripts or
     * an answer that is not a short list of fingerprints.
     *
     * @return list<string>|null
     */
    private function releaseKeys(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 5) {
            return null;
        }
        foreach ($value as $fingerprint) {
            if (! is_string($fingerprint) || preg_match(ReleaseKeys::FINGERPRINT_PATTERN, $fingerprint) !== 1) {
                return null;
            }
        }

        return $value;
    }

    /**
     * PHP versions for websites (E-172): only those the panel offers, in its
     * order; null for an answer without the field or an unexpected one.
     *
     * @return list<string>|null
     */
    private function phpVersions(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 10) {
            return null;
        }
        foreach ($value as $version) {
            if (! is_string($version)) {
                return null;
            }
        }

        return array_values(array_intersect(config('owpanel.php_versions'), $value));
    }

    private function text(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) ? $value : null;
    }
}
