<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Package with technical limits for a whole customer (E-125). Prices and
 * contracts stay in the external system (E-023). Every limit may be null,
 * which means unlimited.
 *
 * max_sites, max_databases, max_cron_jobs: counts over all websites of
 *     the customer
 * storage_mb: files and databases of all websites together (E-126)
 * min_cron_interval: shortest distance between two runs of a cron job,
 *     in minutes
 * daily_self_service: backups and restores the customer starts himself,
 *     per day
 * php_memory_mb, php_upload_mb, php_time_s: the most a website of the
 *     customer may set for memory_limit, uploads and run time (E-173)
 * php_processes: PHP processes of each website at the same time
 */
#[Fillable(['name', 'max_sites', 'storage_mb', 'max_databases', 'max_cron_jobs', 'min_cron_interval', 'daily_self_service', 'php_memory_mb', 'php_upload_mb', 'php_time_s', 'php_processes'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory, HasUlids;

    /**
     * The limits in the order the panel shows them.
     */
    public const LIMITS = ['max_sites', 'storage_mb', 'max_databases', 'max_cron_jobs', 'min_cron_interval', 'daily_self_service'];

    /**
     * Limits for PHP of each website (E-173); empty means up to what the
     * server scripts accept.
     */
    public const PHP_LIMITS = ['php_memory_mb', 'php_upload_mb', 'php_time_s', 'php_processes'];

    /**
     * Limits that are counted against what the customer has; the others
     * are rules (interval) or are measured later (storage, E-126).
     */
    public const COUNTED = ['max_sites' => 'sites', 'max_databases' => 'databases', 'max_cron_jobs' => 'cron_jobs'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_fill_keys([...self::LIMITS, ...self::PHP_LIMITS], 'integer');
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
