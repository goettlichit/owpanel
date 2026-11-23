<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Customer of the hoster. Only name, company, email, language and the
 * number in the external system are stored here; billing data stays
 * outside (docs/SICHERHEIT.md, "Kundenbereich", rule 6).
 *
 * Two numbers (E-085): the panel gives every customer its own number
 * (K10001), which never changes. external_id holds the number of the
 * homepage or another external system and links the two.
 *
 * The package (E-125) is not mass assignable: only the package card on the
 * customer page and the create form set it, each with an audit entry.
 */
#[Fillable(['first_name', 'last_name', 'company', 'email', 'locale', 'external_id'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUlids;

    public const NUMBER_PREFIX = 'K';

    public const FIRST_NUMBER = 10001;

    protected static function booted(): void
    {
        // The next free number. The unique index catches the rare case of
        // two administrators creating a customer in the same moment.
        static::creating(function (Customer $customer): void {
            $customer->number ??= max(self::FIRST_NUMBER, ((int) self::max('number')) + 1);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    /**
     * Company if there is one, otherwise the person.
     *
     * @return Attribute<string, never>
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => filled($this->company) ? (string) $this->company : $this->person_name);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function personName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * The panel's customer number as shown, e.g. K10001.
     *
     * @return Attribute<string, never>
     */
    protected function displayNumber(): Attribute
    {
        return Attribute::get(fn (): string => self::NUMBER_PREFIX.$this->number);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return HasManyThrough<Database, Site, $this>
     */
    public function databases(): HasManyThrough
    {
        return $this->hasManyThrough(Database::class, Site::class);
    }

    /**
     * @return HasManyThrough<CronJob, Site, $this>
     */
    public function cronJobs(): HasManyThrough
    {
        return $this->hasManyThrough(CronJob::class, Site::class);
    }

    /**
     * What the customer has, counted against the package (E-125):
     * websites, databases and cron jobs over all his websites.
     *
     * @return array{sites: int, databases: int, cron_jobs: int}
     */
    public function usage(): array
    {
        return [
            'sites' => $this->sites()->count(),
            'databases' => $this->databases()->count(),
            'cron_jobs' => $this->cronJobs()->count(),
        ];
    }

    /**
     * Limits of the package the customer is over, e.g. after a smaller
     * package was assigned or an administrator went beyond a limit.
     *
     * @param  array{sites: int, databases: int, cron_jobs: int}|null  $usage
     * @return list<string>
     */
    public function exceededLimits(?array $usage = null): array
    {
        if ($this->package === null) {
            return [];
        }
        $usage ??= $this->usage();

        $exceeded = [];
        foreach (Package::COUNTED as $limit => $key) {
            $max = $this->package->{$limit};
            if ($max !== null && $usage[$key] > $max) {
                $exceeded[] = $limit;
            }
        }

        return $exceeded;
    }

    /**
     * Several websites can belong to one customer (E-084).
     *
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
