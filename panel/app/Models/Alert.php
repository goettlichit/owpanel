<?php

namespace App\Models;

use App\Notify\NotificationEvent;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A problem the panel reported (E-101), e.g. "server web1 offline". The key
 * names the problem and its object; while an alert is open, the same
 * problem is not reported again. Resolving it sends the all-clear.
 */
class Alert extends Model
{
    use HasUlids;

    protected $fillable = ['key', 'event', 'data', 'opened_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'data' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Number of open alerts, shown as the badge on the panel's app icon
     * (E-119).
     */
    public static function openCount(): int
    {
        return self::whereNull('resolved_at')->count();
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
