<?php

namespace App\Customers;

use App\Models\Customer;
use App\Models\Site;

/**
 * Storage of a customer against his package (E-126): files and databases
 * of all his websites together. The files of each website get a hard
 * limit on the server: its own files plus what the customer still has
 * free, never below what it uses now. Databases are only measured.
 */
class StorageQuota
{
    /** From this share the bars turn orange, at 100 % red. */
    public const WARNING_PERCENT = 90;

    public static function limitBytes(Customer $customer): ?int
    {
        $mb = $customer->package?->storage_mb;

        return $mb === null ? null : $mb * 1024 * 1024;
    }

    /**
     * Files and databases of all websites; null before any measurement.
     */
    public static function usedBytes(Customer $customer): ?int
    {
        $sites = $customer->relationLoaded('sites') ? $customer->sites : $customer->sites()->get();
        $measured = $sites->filter(fn (Site $site) => $site->usage_measured_at !== null);

        return $measured->isEmpty() ? null : (int) $measured->sum(fn (Site $site) => $site->usageBytes());
    }

    public static function full(Customer $customer): bool
    {
        $limit = self::limitBytes($customer);
        $used = self::usedBytes($customer);

        return $limit !== null && $used !== null && $used >= $limit;
    }

    /**
     * Hard limit for the files of one website in KiB; 0 for none. Websites
     * of the administration, unassigned ones and customers without a
     * storage limit have none.
     */
    public static function targetKib(Site $site): int
    {
        $customer = $site->customer;
        $limit = $customer === null ? null : self::limitBytes($customer);
        if ($limit === null || $site->usage_measured_at === null) {
            return 0;
        }
        $free = max(0, $limit - (int) self::usedBytes($customer));
        $files = (int) $site->usage_files_bytes;

        // At least 1 KiB: 0 would mean no limit at all.
        return max(1, intdiv($files + $free + 1023, 1024));
    }

    /**
     * For bars: used, limit, free and the state ("ok", "warning", "full").
     *
     * @return array{used: int|null, limit: int|null, free: int|null, percent: float|null, state: string}
     */
    public static function summary(?int $used, ?int $limit): array
    {
        $percent = $used !== null && $limit !== null && $limit > 0 ? $used / $limit * 100 : null;

        return [
            'used' => $used,
            'limit' => $limit,
            'free' => $used !== null && $limit !== null ? max(0, $limit - $used) : null,
            'percent' => $percent,
            'state' => match (true) {
                $percent === null => 'ok',
                $percent >= 100 => 'full',
                $percent >= self::WARNING_PERCENT => 'warning',
                default => 'ok',
            },
        ];
    }
}
