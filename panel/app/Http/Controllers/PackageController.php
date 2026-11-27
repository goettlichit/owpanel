<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatus;
use App\Models\Package;
use App\Models\Site;
use App\Sites\PhpSettings;
use App\Sites\SiteDeploy;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Packages under Customers -> Packages (E-125): limits for a whole
 * customer. A change applies to every customer with the package at once;
 * nothing is deleted when a customer ends up over a limit.
 */
class PackageController extends Controller
{
    /**
     * Upper bounds that keep the numbers sane; "unlimited" is an empty field.
     */
    private const MAX = [
        'max_sites' => 10000,
        'storage_mb' => 100_000_000,
        'max_databases' => 10000,
        'max_cron_jobs' => 10000,
        'min_cron_interval' => 1440,
        'daily_self_service' => 100,
    ];

    /**
     * PHP limits (E-173) and the setting of App\Sites\PhpSettings they bound.
     */
    private const PHP = [
        'php_memory_mb' => 'memory',
        'php_upload_mb' => 'upload',
        'php_time_s' => 'time',
        'php_processes' => 'processes',
    ];

    public function index(): View
    {
        Gate::authorize('viewAny', Package::class);

        return view('packages.index', [
            'packages' => Package::withCount('customers')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Package::class);

        // A new package starts with sensible PHP limits (E-173).
        return view('packages.create', ['package' => new Package(config('owpanel.php_settings.package_defaults'))]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Package::class);

        $package = Package::create($this->validated($request));

        Audit::record('package.created', $package, ['name' => $package->name], $request->user());

        return redirect()->route('packages.index')->with('status', __('packages.toast.created', ['name' => $package->name]));
    }

    public function edit(Package $package): View
    {
        Gate::authorize('update', $package);

        return view('packages.edit', [
            'package' => $package,
            'customers' => $package->customers()->orderBy('number')->get(),
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        Gate::authorize('update', $package);

        $package->fill($this->validated($request, $package));
        $changed = array_keys($package->getDirty());
        $package->save();

        if ($changed !== []) {
            Audit::record('package.updated', $package, ['name' => $package->name, 'changed' => $changed], $request->user());
        }
        // Like every limit, PHP limits apply at once (E-125, E-173): the
        // websites of the customers get their settings again.
        if (array_intersect($changed, Package::PHP_LIMITS) !== []) {
            $this->applyPhpLimits($request, $package);
        }

        return redirect()->route('packages.edit', $package)->with('status', __('packages.toast.updated'));
    }

    public function destroy(Request $request, Package $package): RedirectResponse
    {
        Gate::authorize('delete', $package);

        Audit::record('package.deleted', $package, ['name' => $package->name], $request->user());
        $package->delete();

        return redirect()->route('packages.index')->with('status', __('packages.toast.deleted'));
    }

    /**
     * Writes the PHP settings of every active website of the package's
     * customers again. Suspended websites and those with open tasks are left
     * out; they get the limits with their next change.
     */
    private function applyPhpLimits(Request $request, Package $package): void
    {
        Site::query()->whereIn('customer_id', $package->customers()->select('id'))
            ->where('status', SiteStatus::Active->value)->whereNull('suspended_at')
            ->with(['server', 'customer.package'])->get()
            ->reject(fn (Site $site) => $site->hasOpenTasks())
            ->each(fn (Site $site) => SiteDeploy::configure($site, $request->user(), false, settings: PhpSettings::effective($site)));
    }

    /**
     * @return array<string, string|int|null>
     */
    private function validated(Request $request, ?Package $package = null): array
    {
        // Storage is entered in GB (one decimal, e.g. 0.5) and kept in MB.
        $rules = [
            'name' => ['required', 'string', 'max:100', Rule::unique('packages', 'name')->ignore($package)],
            'storage_gb' => ['nullable', 'numeric', 'min:0.1', 'max:'.intdiv(self::MAX['storage_mb'], 1024), 'decimal:0,1'],
        ];
        foreach (array_diff(Package::LIMITS, ['storage_mb']) as $limit) {
            $rules[$limit] = ['nullable', 'integer', $limit === 'min_cron_interval' ? 'min:1' : 'min:0', 'max:'.self::MAX[$limit]];
        }
        foreach (self::PHP as $limit => $key) {
            $rules[$limit] = ['nullable', 'integer', 'min:'.config("owpanel.php_settings.min.{$key}"), 'max:'.config("owpanel.php_settings.max.{$key}")];
        }
        $validated = $request->validate($rules);

        $values = ['name' => trim($validated['name'])];
        foreach (Package::PHP_LIMITS as $limit) {
            $values[$limit] = isset($validated[$limit]) ? (int) $validated[$limit] : null;
        }
        foreach (Package::LIMITS as $limit) {
            $values[$limit] = match (true) {
                $limit === 'storage_mb' => isset($validated['storage_gb']) ? (int) round((float) $validated['storage_gb'] * 1024) : null,
                default => isset($validated[$limit]) ? (int) $validated[$limit] : null,
            };
        }

        return $values;
    }
}
