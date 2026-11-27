<?php

namespace App\Models;

use App\Dns\SiteDnsWriter;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Servers\ScriptCall;
use App\Sites\Domain;
use App\Sites\SiteDeploy;
use App\Support\Audit;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A website on a server (E-074). Created and deleted by the server scripts
 * site-create and site-delete, which run as server tasks. The state here
 * follows the outcome of those tasks (taskFinished()).
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasUlids;

    /**
     * Site users are "ow" plus a number from 10001 to 59999. ow10000 is the
     * panel itself (E-034, E-041).
     */
    public const USER_MIN = 10001;

    public const USER_MAX = 59999;

    public const TYPE_PHP = 'php';

    /**
     * Who a website belongs to (E-139). Only websites of a customer are
     * bound to the limits of his package.
     */
    public const OWNER_CUSTOMER = 'customer';

    public const OWNER_ADMIN = 'admin';

    public const OWNER_UNASSIGNED = 'unassigned';

    /**
     * Certbot renews 30 days before expiry. Fewer days left than this mean
     * the renewal is failing (E-076).
     */
    public const CERTIFICATE_WARNING_DAYS = 20;

    protected $attributes = [
        'type' => self::TYPE_PHP,
        'status' => 'pending',
        'wants_tls' => true,
        'tls' => false,
        'www' => false,
        'delete_dns_records' => false,
        'www_tls' => false,
        'admin_owned' => false,
    ];

    /**
     * Server, customer, Linux user and state are set by the panel itself,
     * never from a request.
     */
    protected $guarded = ['*'];

    protected $hidden = ['sftp_password'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SiteStatus::class,
            'wants_tls' => 'boolean',
            'tls' => 'boolean',
            'www' => 'boolean',
            // PHP settings (E-173); null means the default.
            'php_memory_mb' => 'integer',
            'php_upload_mb' => 'integer',
            'php_time_s' => 'integer',
            'php_display_errors' => 'boolean',
            'nginx_headers' => 'array',
            'nginx_blocked_paths' => 'array',
            'delete_dns_records' => 'boolean',
            'www_tls' => 'boolean',
            'admin_owned' => 'boolean',
            'certificate_expires_at' => 'datetime',
            // Storage measured by site-usage (E-126).
            'usage_files_bytes' => 'integer',
            'usage_db_bytes' => 'integer',
            'usage_measured_at' => 'datetime',
            'quota_kib' => 'integer',
            // Suspension (E-127).
            'suspended_at' => 'datetime',
            'sftp_enabled' => 'boolean',
            'backup_enabled' => 'boolean',
            // Shown again on request, recorded in the audit log (E-089, E-135).
            'sftp_password' => 'encrypted',
        ];
    }

    /**
     * Picks a random free Linux user. The number says nothing about the
     * number of sites (E-034). The unique index on system_user is the final
     * guard against two requests picking the same number.
     */
    public static function freeSystemUser(): string
    {
        for ($i = 0; $i < 100; $i++) {
            $user = 'ow'.random_int(self::USER_MIN, self::USER_MAX);

            if (! self::where('system_user', $user)->exists()) {
                return $user;
            }
        }

        throw new RuntimeException('No free site user found.');
    }

    /**
     * Domain for the interface, internationalised names in Unicode.
     *
     * @return Attribute<string, never>
     */
    protected function displayDomain(): Attribute
    {
        return Attribute::get(fn () => Domain::display($this->domain));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function rootPath(): Attribute
    {
        return Attribute::get(fn () => '/var/www/'.$this->system_user);
    }

    /**
     * Whether a server task for this site is queued or running. Only one may
     * run at a time.
     */
    /**
     * @return HasMany<SiteVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(SiteVisit::class);
    }

    /**
     * Suspended by an administrator or the API (E-127): the website shows a
     * neutral page, and nothing about it may change.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * The state for badges in all lists and overviews: a suspension
     * (E-127) shows instead of "active".
     */
    public function badgeStatus(): string
    {
        return $this->isSuspended() ? 'suspended' : $this->status->value;
    }

    /**
     * Files and databases together, null before the first measurement.
     */
    public function usageBytes(): ?int
    {
        return $this->usage_measured_at === null ? null : (int) $this->usage_files_bytes + (int) $this->usage_db_bytes;
    }

    public function owner(): string
    {
        return match (true) {
            $this->customer_id !== null => self::OWNER_CUSTOMER,
            $this->admin_owned => self::OWNER_ADMIN,
            default => self::OWNER_UNASSIGNED,
        };
    }

    public function isBusy(): bool
    {
        return $this->tasks()
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])
            ->exists();
    }

    public function latestTask(): ?ServerTask
    {
        return $this->tasks()->latest('created_at')->latest('id')->first();
    }

    /**
     * Whether the last attempt to create the site failed and can be repeated.
     * site-create is repeatable and brings a half-created site into shape.
     */
    public function canRetryCreate(): bool
    {
        $latest = $this->latestTask();

        return $this->status === SiteStatus::Failed
            && ($latest?->script === ServerScript::SiteCreate
                || ($latest?->script === ServerScript::GatewaySite && ! in_array('--remove', (array) $latest->arguments, true)))
            && ! $this->isBusy();
    }

    /**
     * Whether a certificate can be requested for a site that runs on HTTP
     * only. site-create --tls adds it without changing anything else.
     */
    public function canRequestCertificate(): bool
    {
        return $this->status === SiteStatus::Active && ! $this->tls && ! $this->isBusy();
    }

    /**
     * The site has a certificate, but it lacks www.<domain>, e.g. because
     * its DNS entry was missing (E-110).
     */
    public function canRequestWwwCertificate(): bool
    {
        return $this->status === SiteStatus::Active && $this->tls && $this->www && ! $this->www_tls && ! $this->isBusy();
    }

    /**
     * "none", "valid", "expiring" (fewer than 20 days left), "expired" or
     * "unknown" if the expiry has not been read yet.
     */
    public function certificateState(): string
    {
        if (! $this->tls) {
            return 'none';
        }

        if ($this->certificate_expires_at === null) {
            return 'unknown';
        }

        return match (true) {
            $this->certificate_expires_at->isPast() => 'expired',
            $this->certificate_expires_at->lessThan(now()->addDays(self::CERTIFICATE_WARNING_DAYS)) => 'expiring',
            default => 'valid',
        };
    }

    /**
     * www.<domain> can be added to a domain that does not start with www.
     * and stays a valid host name with it (E-110).
     */
    public static function wwwPossible(string $domain): bool
    {
        return ! str_starts_with($domain, 'www.') && Domain::isValid('www.'.$domain);
    }

    /**
     * Whether the panel offers www.<domain> (E-120): only for main domains
     * such as example.de, not for subdomains such as shop.example.de.
     * Websites that already have www on a subdomain keep it and can turn
     * it off.
     */
    public static function wwwOffered(string $domain): bool
    {
        return self::wwwPossible($domain) && Domain::isMain($domain);
    }

    /**
     * Whether a host name already belongs to a website, as its domain or
     * as its www name (E-110). $except leaves out one website.
     */
    public static function nameTaken(string $name, ?self $except = null): bool
    {
        $query = self::query()->when($except, fn ($q) => $q->whereKeyNot($except->getKey()));

        return (clone $query)->where('domain', $name)->exists()
            || (str_starts_with($name, 'www.') && (clone $query)->where('domain', substr($name, 4))->where('www', true)->exists());
    }

    /**
     * The panel knows the website as active, but the server's last status
     * check did not find its directory, e.g. after the panel was restored
     * on a new server (E-106). Unknown without a check that lists the
     * websites; never while a task of the website waits or runs, or when it
     * was created after the check began.
     */
    public function missingOnServer(): bool
    {
        $server = $this->server;
        $present = $server?->present_sites;
        if ($this->status !== SiteStatus::Active || ! is_array($present) || $server->checked_at === null
            || in_array($this->system_user, $present, true)) {
            return false;
        }

        return ! $this->hasOpenTasks()
            && ! $this->tasks()->where('script', ServerScript::SiteCreate->value)
                ->where('finished_at', '>=', $server->checked_at)->exists();
    }

    /**
     * A task of the website waits or runs, e.g. a backup or a step of a
     * rebuild (E-106).
     */
    public function hasOpenTasks(): bool
    {
        return $this->tasks()->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])->exists();
    }

    /**
     * Applies the outcome of a finished site-create or site-delete task.
     * Called by the queue worker (App\Jobs\RunServerTask).
     */
    public function taskFinished(ServerTask $task): void
    {
        $succeeded = $task->status === TaskStatus::Succeeded;

        match ($task->script) {
            // The backend part (E-195); the gateway after it decides.
            ServerScript::SiteCreate => $succeeded
                ? $this->backendWritten($task)
                : $this->createFailed(),
            ServerScript::GatewaySite => match (true) {
                in_array('--remove', (array) $task->arguments, true) => $succeeded
                    ? null
                    : $this->forceFill(['status' => SiteStatus::Failed])->save(),
                $succeeded => $this->activated($task),
                default => $this->createFailed(),
            },
            ServerScript::SiteDelete => $succeeded
                ? $this->deleteAfterScript($task)
                : $this->forceFill(['status' => SiteStatus::Failed])->save(),
            default => null,
        };
    }

    private function activated(ServerTask $task): void
    {
        $wasActive = $this->status === SiteStatus::Active;
        $this->takeOverDomain($task);
        $this->forceFill([
            'status' => SiteStatus::Active,
            'tls' => ($task->result['tls'] ?? false) === true,
            'www_tls' => ($task->result['www_tls'] ?? false) === true,
            'certificate_expires_at' => $this->reportedExpiry($task),
        ])->save();
        // Session cookies follow a new or lost certificate (E-176), and the
        // backend lets go of a previous domain (E-112).
        SiteDeploy::followGateway($this, $task);

        // Cron jobs and SFTP set up while the website was being created
        // (E-088, E-089). Both tasks read their data when they run.
        if (! $wasActive && $this->cronJobs()->where('enabled', true)->exists()) {
            ServerTask::start($this->server, ScriptCall::siteCron($this->system_user, []), null, $this);
        }
        if (! $wasActive && $this->sftp_enabled) {
            ServerTask::start($this->server, ScriptCall::siteSftp($this->system_user, false), null, $this);
        }
    }

    /**
     * The backend reports the PHP version it runs (E-172).
     */
    private function backendWritten(ServerTask $task): void
    {
        $php = $this->reportedPhpVersion($task);
        if ($php !== null && $php !== $this->php_version) {
            $this->forceFill(['php_version' => $php])->save();
        }
    }

    /**
     * After a domain change (E-112) the site answers at the domain the task
     * asked for, once the script reports exactly that domain. The www
     * address follows the task too: it is dropped if the new domain does
     * not allow it.
     */
    private function takeOverDomain(ServerTask $task): void
    {
        $arguments = is_array($task->arguments) ? $task->arguments : [];
        $previous = array_search('--previous-domain', $arguments, true);
        $requested = array_search('--domain', $arguments, true);
        if ($previous === false || $requested === false) {
            return;
        }

        $domain = $arguments[$requested + 1] ?? null;
        if (! is_string($domain) || ($task->result['domain'] ?? null) !== $domain || ! Domain::isValid($domain)
            || $domain === $this->domain || self::nameTaken($domain, $this)) {
            return;
        }

        $from = $this->domain;
        $this->forceFill(['domain' => $domain, 'www' => in_array('--www', $arguments, true)])->save();
        Audit::record('site.domain_changed', $this, ['from' => $from, 'to' => $domain], $task->user);
    }

    /**
     * A failed certificate request leaves a working HTTP site active; only
     * a failed creation marks the site as failed.
     */
    private function createFailed(): void
    {
        if ($this->status === SiteStatus::Active) {
            $this->forceFill(['wants_tls' => $this->tls])->save();

            return;
        }

        $this->forceFill(['status' => SiteStatus::Failed])->save();
    }

    private function reportedExpiry(ServerTask $task): ?Carbon
    {
        $expires = $task->result['certificate_expires'] ?? null;

        return is_int($expires) && $expires > 0 ? Carbon::createFromTimestamp($expires) : null;
    }

    private function reportedPhpVersion(ServerTask $task): ?string
    {
        $php = $task->result['php'] ?? null;

        return is_string($php) && preg_match('/^\d+\.\d+$/', $php) ? $php : null;
    }

    /**
     * The server no longer has the site, so the panel forgets it. The task
     * stays with its log; the audit log keeps domain and user.
     */
    private function deleteAfterScript(ServerTask $task): void
    {
        // The panel's DNS records go too, if that was chosen (E-116); a
        // failure there never stops the deletion.
        if ($this->delete_dns_records) {
            try {
                app(SiteDnsWriter::class)->removeOwned($this, $task->user);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        Audit::record('site.deleted', $this, [
            'domain' => $this->domain,
            'system_user' => $this->system_user,
            'server' => $this->server_id,
        ], $task->user);

        $this->delete();
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * DNS records the panel set for this website (E-116).
     *
     * @return HasMany<SiteDnsRecord, $this>
     */
    public function dnsRecords(): HasMany
    {
        return $this->hasMany(SiteDnsRecord::class);
    }

    /**
     * @return HasMany<CronJob, $this>
     */
    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    /**
     * @return HasMany<SftpKey, $this>
     */
    public function sftpKeys(): HasMany
    {
        return $this->hasMany(SftpKey::class);
    }

    /**
     * The task that writes the current SFTP state, read when it runs.
     */
    public function sftpCall(): ScriptCall
    {
        return ScriptCall::siteSftp(
            $this->system_user,
            $this->sftp_enabled,
            $this->sftp_enabled ? $this->sftp_password : null,
            $this->sftp_enabled ? $this->sftpKeys()->orderBy('created_at')->orderBy('id')->pluck('public_key')->all() : [],
        );
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Database, $this>
     */
    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    /**
     * @return HasMany<ServerTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ServerTask::class);
    }
}
