<?php

namespace App\Hetzner;

use Illuminate\Support\Carbon;

/**
 * Which panel snapshots to keep (E-095): the newest of each of the last
 * $daily days and of each of the last $weekly ISO weeks, counted from the
 * newest snapshot. The newest one always stays. Everything else goes.
 */
final class SnapshotRotation
{
    /**
     * @param  list<array{id: int, created: string}>  $snapshots  available panel snapshots of one server
     * @return list<int> ids to delete
     */
    public static function toDelete(array $snapshots, int $daily, int $weekly, string $timezone): array
    {
        if ($snapshots === []) {
            return [];
        }
        usort($snapshots, fn (array $a, array $b) => strcmp($b['created'], $a['created']));

        $keep = [$snapshots[0]['id'] => true];
        $days = [];
        $weeks = [];
        foreach ($snapshots as $snapshot) {
            $time = Carbon::parse($snapshot['created'])->setTimezone($timezone);
            $day = $time->format('Y-m-d');
            $week = $time->format('o-W');
            if (! isset($days[$day]) && count($days) < $daily) {
                $days[$day] = true;
                $keep[$snapshot['id']] = true;
            }
            if (! isset($weeks[$week]) && count($weeks) < $weekly) {
                $weeks[$week] = true;
                $keep[$snapshot['id']] = true;
            }
        }

        return array_values(array_map(
            fn (array $snapshot) => $snapshot['id'],
            array_filter($snapshots, fn (array $snapshot) => ! isset($keep[$snapshot['id']])),
        ));
    }
}
