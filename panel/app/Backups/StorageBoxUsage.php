<?php

namespace App\Backups;

use App\Hetzner\HetznerCloud;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fill level of the Storage Boxes that hold backups (E-160). Read once an
 * hour from the Hetzner API; the alert checker warns from 90 %.
 */
class StorageBoxUsage
{
    /**
     * Reads every box in use and stores the result. A box that cannot be
     * read keeps its last reading; boxes no longer in use drop out.
     */
    public static function refresh(): void
    {
        $ids = Server::whereNotNull('backup_box_id')->distinct()->pluck('backup_box_id')->map(fn ($id) => (int) $id)->all();
        $known = self::all();
        $boxes = [];
        if ($ids !== [] && HetznerCloud::configured()) {
            $api = HetznerCloud::fromSettings();
            foreach ($ids as $id) {
                try {
                    $usage = $api->storageBoxUsage($id);
                } catch (RuntimeException $e) {
                    Log::warning('Storage Box usage not readable', ['box' => $id, 'error' => $e->getMessage()]);
                    $usage = null;
                }
                if ($usage !== null) {
                    $boxes[$id] = $usage + ['checked_at' => now()->toIso8601String()];
                } elseif (isset($known[$id])) {
                    $boxes[$id] = $known[$id];
                }
            }
        }
        Setting::put(Setting::STORAGE_BOXES, $boxes === [] ? null : (string) json_encode($boxes));
    }

    /**
     * @return array<int, array{name: string, used: int, total: int, checked_at: string}>
     */
    public static function all(): array
    {
        $boxes = json_decode((string) Setting::get(Setting::STORAGE_BOXES), true);

        return is_array($boxes) ? $boxes : [];
    }

    public static function percent(array $box): float
    {
        return $box['total'] > 0 ? round($box['used'] / $box['total'] * 100, 1) : 0.0;
    }
}
