<?php

namespace App\Servers;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Support\Carbon;

/**
 * Turns the stored measurements of a server into evenly spaced points for
 * the charts on the load page (E-075). Each range is split into buckets;
 * a bucket holds the average of its measurements, or null if the server did
 * not answer then, so the chart shows a gap instead of a made-up line.
 */
final class LoadHistory
{
    /**
     * Range => [minutes, minutes per bucket]. Every range ends up with
     * 60 to 340 points.
     */
    public const RANGES = [
        '1h' => [60, 1],
        '24h' => [1440, 5],
        '7d' => [10080, 30],
    ];

    public const DEFAULT_RANGE = '24h';

    /**
     * A gap of more than this between two measurements gives no network
     * rate, since an average over a long outage would mislead.
     */
    private const MAX_RATE_GAP_SECONDS = 600;

    /**
     * "interfaces" holds the rates per network card of the newest
     * measurement, the public one first (E-111); empty for older data.
     *
     * @return array{times: list<int>, bucket: int, cpu: list<?float>, memory: list<?float>, disk: list<?float>, load: list<?float>, rx: list<?float>, tx: list<?float>, interfaces: list<array{name: string, public: bool, rx: list<?float>, tx: list<?float>}>}
     */
    public static function for(Server $server, string $range, ?Carbon $now = null): array
    {
        [$minutes, $bucketMinutes] = self::RANGES[$range] ?? self::RANGES[self::DEFAULT_RANGE];
        $bucketSeconds = $bucketMinutes * 60;
        $count = intdiv($minutes, $bucketMinutes);

        // The last bucket ends with the current one, so it may be partial.
        $end = ($now ?? now())->copy()->startOfMinute()->addMinute();
        $endTs = (int) ceil($end->getTimestamp() / $bucketSeconds) * $bucketSeconds;
        $startTs = $endTs - $count * $bucketSeconds;

        $sums = [];
        foreach (['cpu', 'memory', 'disk', 'load', 'rx', 'tx'] as $key) {
            $sums[$key] = array_fill(0, $count, [0.0, 0]);
        }

        $previous = null;
        $rows = $server->metrics()
            // One row more on the left, for the first network rate.
            ->where('recorded_at', '>=', Carbon::createFromTimestamp($startTs - self::MAX_RATE_GAP_SECONDS))
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'cpu_percent', 'load1', 'memory_total', 'memory_used', 'disk_total', 'disk_used', 'network_rx', 'network_tx', 'network_interfaces']);
        $cards = [];

        foreach ($rows as $row) {
            $ts = $row->recorded_at->getTimestamp();
            $index = intdiv($ts - $startTs, $bucketSeconds);
            $rates = self::rates($previous, $row);
            $cardRates = [];
            foreach ($row->interfaces() as $name => $counters) {
                $before = $previous?->interfaces()[$name] ?? null;
                $cardRates[$name] = self::between($previous, $row, $before === null ? null : [$before['rx'], $before['tx']], [$counters['rx'], $counters['tx']]);
            }
            $previous = $row;

            if ($ts < $startTs || $index >= $count) {
                continue;
            }

            foreach ($cardRates as $name => [$rx, $tx]) {
                $cards[$name] ??= ['rx' => array_fill(0, $count, [0.0, 0]), 'tx' => array_fill(0, $count, [0.0, 0])];
                foreach (['rx' => $rx, 'tx' => $tx] as $key => $value) {
                    if ($value !== null) {
                        $cards[$name][$key][$index][0] += $value;
                        $cards[$name][$key][$index][1]++;
                    }
                }
            }

            $values = [
                'cpu' => $row->cpu_percent,
                'memory' => $row->memoryPercent(),
                'disk' => $row->diskPercent(),
                'load' => $row->load1,
                'rx' => $rates[0],
                'tx' => $rates[1],
            ];

            foreach ($values as $key => $value) {
                if ($value !== null) {
                    $sums[$key][$index][0] += $value;
                    $sums[$key][$index][1]++;
                }
            }
        }

        $result = ['times' => [], 'bucket' => $bucketSeconds];
        for ($i = 0; $i < $count; $i++) {
            $result['times'][] = $startTs + $i * $bucketSeconds;
        }
        $average = fn (array $buckets) => array_map(
            fn (array $bucket) => $bucket[1] > 0 ? round($bucket[0] / $bucket[1], 2) : null,
            $buckets,
        );
        foreach ($sums as $key => $buckets) {
            $result[$key] = $average($buckets);
        }

        // The cards of the newest measurement; older names, e.g. after a
        // renamed interface, are left out.
        $result['interfaces'] = [];
        foreach ($previous?->interfaces() ?? [] as $name => $counters) {
            $result['interfaces'][] = [
                'name' => (string) $name,
                'public' => (bool) $counters['public'],
                'rx' => $average($cards[$name]['rx'] ?? array_fill(0, $count, [0.0, 0])),
                'tx' => $average($cards[$name]['tx'] ?? array_fill(0, $count, [0.0, 0])),
            ];
        }

        return $result;
    }

    /**
     * Bytes per second received and sent between two measurements, for all
     * cards together.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private static function rates(?ServerMetric $previous, ServerMetric $current): array
    {
        return self::between($previous, $current, $previous === null ? null : [$previous->network_rx, $previous->network_tx], [$current->network_rx, $current->network_tx]);
    }

    /**
     * Rates from two pairs of counters. Null after a reboot (counters start
     * again), a long gap or a card the previous measurement lacked.
     *
     * @param  array{0: int, 1: int}|null  $before
     * @param  array{0: int, 1: int}  $after
     * @return array{0: ?float, 1: ?float}
     */
    private static function between(?ServerMetric $previous, ServerMetric $current, ?array $before, array $after): array
    {
        if ($previous === null || $before === null) {
            return [null, null];
        }

        $seconds = $current->recorded_at->getTimestamp() - $previous->recorded_at->getTimestamp();
        if ($seconds <= 0 || $seconds > self::MAX_RATE_GAP_SECONDS) {
            return [null, null];
        }

        $rx = $after[0] - $before[0];
        $tx = $after[1] - $before[1];

        return [
            $rx >= 0 ? $rx / $seconds : null,
            $tx >= 0 ? $tx / $seconds : null,
        ];
    }
}
