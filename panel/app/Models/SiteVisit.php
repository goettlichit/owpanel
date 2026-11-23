<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visitors and page views of one website on one day (E-148), from
 * site-stats. A visitor is one IP address with one browser on that day,
 * counted on the server; the panel never sees the addresses.
 */
class SiteVisit extends Model
{
    use Prunable;

    /** How long days are kept. */
    public const KEEP_MONTHS = 14;

    protected $fillable = ['date', 'visitors', 'pageviews', 'pages'];

    /**
     * "date" stays a plain "Y-m-d" string, so updateOrCreate finds a day
     * again in MariaDB and SQLite alike.
     */
    protected function casts(): array
    {
        return [
            'visitors' => 'integer',
            'pageviews' => 'integer',
            'pages' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('date', '<', now()->subMonths(self::KEEP_MONTHS)->toDateString());
    }
}
