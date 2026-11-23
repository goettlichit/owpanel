<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cron job of a website (E-088). It runs as the website's Linux user in
 * its htdocs directory; the output goes to the journal and is shown under
 * the website's logs. Changes reach the server with the task site-cron.
 */
class CronJob extends Model
{
    use HasFactory, HasUlids;

    public const MAX_COMMAND = 1000;

    public const MAX_PER_SITE = 50;

    /**
     * Allowed values per time field: minute, hour, day, month, weekday.
     */
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    /**
     * Common schedules for the form.
     */
    public const PRESETS = [
        'every_minute' => '* * * * *',
        'every_5_minutes' => '*/5 * * * *',
        'every_15_minutes' => '*/15 * * * *',
        'hourly' => '0 * * * *',
        'daily' => '0 3 * * *',
        'weekly' => '0 3 * * 1',
        'monthly' => '0 3 1 * *',
    ];

    protected $fillable = ['schedule', 'command', 'description', 'enabled'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Five fields with digits, *, ranges, lists and steps, each within its
     * range; the same rule as in site-cron, plus the ranges.
     */
    public static function validSchedule(string $schedule): bool
    {
        $fields = preg_split('/\s+/', trim($schedule));
        if (! is_array($fields) || count($fields) !== 5) {
            return false;
        }

        foreach ($fields as $index => $field) {
            [$min, $max] = self::RANGES[$index];
            foreach (explode(',', $field) as $part) {
                if (! preg_match('/^(\*|(\d{1,2})(?:-(\d{1,2}))?)(?:\/(\d{1,2}))?$/', $part, $match)) {
                    return false;
                }
                $from = $match[2] ?? '';
                $to = $match[3] ?? '';
                $step = $match[4] ?? '';
                if ($from !== '' && ((int) $from < $min || (int) $from > $max)) {
                    return false;
                }
                if ($to !== '' && ((int) $to < (int) $from || (int) $to > $max)) {
                    return false;
                }
                if ($step !== '' && (int) $step < 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Printable, one line, not empty; the same rule as in site-cron.
     */
    public static function validCommand(string $command): bool
    {
        return trim($command) !== '' && mb_strlen($command) <= self::MAX_COMMAND && ! preg_match('/[\x00-\x1F\x7F]/', $command);
    }

    /**
     * The schedule with single spaces, as written to the server.
     */
    public static function normalizeSchedule(string $schedule): string
    {
        return implode(' ', preg_split('/\s+/', trim($schedule)) ?: []);
    }

    /**
     * Shortest distance between two runs in minutes, for the package limit
     * (E-125, E-139). Looks at the times of one day and at the days the job
     * runs over a little more than two years, so steps, lists, ranges and
     * day rules all count. Null for a schedule that never runs (31 2).
     * Expects a valid schedule.
     */
    public static function shortestInterval(string $schedule): ?int
    {
        [$minute, $hour, $day, $month, $weekday] = preg_split('/\s+/', trim($schedule)) ?: [];

        $minutes = self::expand($minute, 0);
        $hours = self::expand($hour, 1);
        $times = [];
        foreach ($hours as $h) {
            foreach ($minutes as $m) {
                $times[] = $h * 60 + $m;
            }
        }
        sort($times);

        $shortest = null;
        for ($i = 1; $i < count($times); $i++) {
            $shortest = min($shortest ?? PHP_INT_MAX, $times[$i] - $times[$i - 1]);
        }

        // Days with runs. Like cron: if day and weekday are both
        // restricted, either one is enough.
        $days = self::expand($day, 2);
        $months = self::expand($month, 3);
        $weekdays = array_map(fn (int $w) => $w % 7, self::expand($weekday, 4));
        $dayRestricted = ! str_starts_with($day, '*');
        $weekdayRestricted = ! str_starts_with($weekday, '*');

        $date = new \DateTimeImmutable('2025-01-01');
        $previous = null;
        for ($index = 0; $index < 800; $index++, $date = $date->modify('+1 day')) {
            if (! in_array((int) $date->format('n'), $months, true)) {
                continue;
            }
            $dayMatch = in_array((int) $date->format('j'), $days, true);
            $weekdayMatch = in_array((int) $date->format('w'), $weekdays, true);
            $runs = $dayRestricted && $weekdayRestricted ? ($dayMatch || $weekdayMatch) : ($dayMatch && $weekdayMatch);
            if (! $runs) {
                continue;
            }
            if ($previous !== null) {
                $gap = ($index - $previous) * 1440 - end($times) + $times[0];
                $shortest = min($shortest ?? PHP_INT_MAX, $gap);
            }
            $previous = $index;
        }

        return $shortest;
    }

    /**
     * The values one field stands for.
     *
     * @return list<int>
     */
    private static function expand(string $field, int $index): array
    {
        [$min, $max] = self::RANGES[$index];
        $values = [];
        foreach (explode(',', $field) as $part) {
            preg_match('/^(\*|(\d{1,2})(?:-(\d{1,2}))?)(?:\/(\d{1,2}))?$/', $part, $match);
            $step = (int) ($match[4] ?? '') ?: 1;
            if ($match[1] === '*') {
                [$from, $to] = [$min, $max];
            } else {
                $from = (int) $match[2];
                // "5/10" means from 5 to the end, like cron.
                $to = ($match[3] ?? '') !== '' ? (int) $match[3] : (($match[4] ?? '') !== '' ? $max : $from);
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Name of the preset, if the schedule is one.
     */
    public function presetName(): ?string
    {
        $key = array_search($this->schedule, self::PRESETS, true);

        return $key === false ? null : $key;
    }
}
