<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One measurement of server-status (E-075). Written once a minute per
 * server; `php artisan model:prune` removes rows older than seven days.
 */
class ServerMetric extends Model
{
    use Prunable;

    public const KEEP_DAYS = 7;

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'cpu_percent' => 'float',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'memory_total' => 'integer',
            'memory_used' => 'integer',
            'swap_total' => 'integer',
            'swap_used' => 'integer',
            'disk_total' => 'integer',
            'disk_used' => 'integer',
            'network_rx' => 'integer',
            'network_tx' => 'integer',
            'network_interfaces' => 'array',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('recorded_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function memoryPercent(): float
    {
        return self::percent($this->memory_used, $this->memory_total);
    }

    public function diskPercent(): float
    {
        return self::percent($this->disk_used, $this->disk_total);
    }

    public function swapPercent(): float
    {
        return self::percent($this->swap_used, $this->swap_total);
    }

    /**
     * Counters per network card, the public one first (E-111).
     *
     * @return array<string, array{rx: int, tx: int, public: bool}>
     */
    public function interfaces(): array
    {
        $interfaces = is_array($this->network_interfaces) ? $this->network_interfaces : [];
        uksort($interfaces, fn (string $a, string $b) => [! ($interfaces[$a]['public'] ?? false), $a] <=> [! ($interfaces[$b]['public'] ?? false), $b]);

        return $interfaces;
    }

    public static function percent(int $used, int $total): float
    {
        return $total > 0 ? round(min(100, $used * 100 / $total), 1) : 0.0;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
