<?php

namespace App\Customers;

use App\Enums\ServerScript;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\Package;
use App\Models\ServerTask;
use App\Models\Site;
use App\Support\Units;

/**
 * Checks actions against the package of the customer a website belongs to
 * (E-125, E-139). Only websites of a customer with a package have limits;
 * websites of the administration and unassigned websites have none.
 *
 * Every method returns the broken limits, keyed by the limit, as translated
 * sentences; an empty list means the action fits. What happens then is up to the caller:
 * customers are refused, administrators may go beyond the limit after a
 * confirmation (App\Http\Controllers\Concerns\ChecksPackageLimits).
 */
class PackageLimits
{
    public static function package(Site $site): ?Package
    {
        return $site->customer?->package;
    }

    /**
     * A new or changed cron job. $job is the job being changed, so it is
     * not counted twice.
     *
     * @return array<string, string>
     */
    public static function forCronJob(Site $site, string $schedule, ?CronJob $job = null): array
    {
        $package = self::package($site);
        if ($package === null) {
            return [];
        }

        $broken = [];
        if ($job === null && $package->max_cron_jobs !== null && $site->customer->cronJobs()->count() >= $package->max_cron_jobs) {
            $broken['max_cron_jobs'] = __('packages.limit_broken.max_cron_jobs', ['max' => $package->max_cron_jobs]);
        }
        if (! self::scheduleAllowed($package, $schedule)) {
            $broken['min_cron_interval'] = __('packages.limit_broken.min_cron_interval', ['interval' => __('packages.units.minutes', ['count' => $package->min_cron_interval])]);
        }

        return $broken;
    }

    /**
     * @return array<string, string>
     */
    public static function forDatabase(Site $site): array
    {
        $package = self::package($site);
        if ($package === null) {
            return [];
        }

        $broken = [];
        if ($package->max_databases !== null && $site->customer->databases()->count() >= $package->max_databases) {
            $broken['max_databases'] = __('packages.limit_broken.max_databases', ['max' => $package->max_databases]);
        }

        return $broken + self::forStorage($site->customer);
    }

    /**
     * Something that needs storage, such as a new database or an import:
     * not when the storage of the package is used up (E-126).
     *
     * @return array<string, string>
     */
    public static function forStorage(?Customer $customer): array
    {
        return $customer !== null && StorageQuota::full($customer)
            ? ['storage_mb' => __('packages.limit_broken.storage_mb', ['max' => Units::bytes((int) StorageQuota::limitBytes($customer))])]
            : [];
    }

    /**
     * A website that is created for the customer ($site null) or moved to
     * him. A moved website brings its databases and cron jobs along.
     *
     * @return array<string, string>
     */
    public static function forSite(Customer $customer, ?Site $site = null): array
    {
        $package = $customer->package;
        if ($package === null || $site?->customer_id === $customer->id) {
            return [];
        }

        $usage = $customer->usage();
        $adds = [
            'sites' => 1,
            'databases' => $site?->databases()->count() ?? 0,
            'cron_jobs' => $site?->cronJobs()->count() ?? 0,
        ];

        $broken = [];
        foreach (Package::COUNTED as $limit => $key) {
            $max = $package->{$limit};
            if ($max !== null && $adds[$key] > 0 && $max < $usage[$key] + $adds[$key]) {
                $broken[$limit] = __('packages.limit_broken.'.$limit, ['max' => $max]);
            }
        }
        if ($site !== null && $site->cronJobs()->get()->contains(fn (CronJob $job) => ! self::scheduleAllowed($package, $job->schedule))) {
            $broken['min_cron_interval'] = __('packages.limit_broken.min_cron_interval', ['interval' => __('packages.units.minutes', ['count' => $package->min_cron_interval])]);
        }

        return $broken;
    }

    /**
     * Backups and restores a customer starts himself today, in the panel's
     * time zone (E-125, E-145). Those of administrators do not count.
     */
    public static function selfServiceToday(Customer $customer): int
    {
        return ServerTask::query()
            ->whereIn('script', [ServerScript::SiteBackup->value, ServerScript::SiteRestore->value])
            ->whereIn('user_id', $customer->users()->select('id'))
            ->where('created_at', '>=', now(config('owpanel.timezone'))->startOfDay()->utc())
            ->count();
    }

    /**
     * One more backup or restore by the customer himself.
     *
     * @return array<string, string>
     */
    public static function forSelfService(Customer $customer): array
    {
        $max = $customer->package?->daily_self_service;
        if ($max === null || self::selfServiceToday($customer) < $max) {
            return [];
        }

        return ['daily_self_service' => __('packages.limit_broken.daily_self_service', ['max' => $max])];
    }

    /**
     * Why a customer cannot create a cron job or a database right now, for
     * a disabled button with a note (E-146); null when he can. Only the
     * limits that do not depend on the input: no package, number reached.
     */
    public static function customerBlock(Site $site, string $kind): ?string
    {
        $package = self::package($site);
        if ($package === null) {
            return __('my.package.none');
        }
        $broken = $kind === 'cron' ? self::forCronJob($site, '0 0 1 1 *') : self::forDatabase($site);
        $key = $kind === 'cron' ? 'max_cron_jobs' : 'max_databases';
        if (isset($broken[$key])) {
            return __('my.limits.'.$kind, ['max' => $package->{$key}]);
        }

        return isset($broken['storage_mb']) ? __('my.limits.storage') : null;
    }

    public static function scheduleAllowed(?Package $package, string $schedule): bool
    {
        if ($package?->min_cron_interval === null) {
            return true;
        }
        $interval = CronJob::shortestInterval($schedule);

        return $interval === null || $interval >= $package->min_cron_interval;
    }
}
