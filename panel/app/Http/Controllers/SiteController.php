<?php

namespace App\Http\Controllers;

use App\Customers\PackageLimits;
use App\Dns\SiteDnsWriter;
use App\Enums\SiteStatus;
use App\Hetzner\HetznerCloud;
use App\Http\Controllers\Concerns\ChecksPackageLimits;
use App\Models\Customer;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;
use App\Sites\Domain;
use App\Sites\PhpSettings;
use App\Support\Area;
use App\Support\Audit;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Websites (E-074). Creating and deleting only queue the server scripts
 * site-create and site-delete; the web request never talks to a server
 * (docs/ARCHITEKTUR.md, "Ablauf: Website anlegen").
 */
class SiteController extends Controller
{
    use ChecksPackageLimits;

    public function index(): View
    {
        Gate::authorize('viewAny', Site::class);

        // Three groups (E-139): websites of the administration, unassigned
        // ones and those of customers, each alphabetically by the readable
        // domain.
        $sites = Site::with(['server', 'customer'])->get()
            ->sortBy(fn (Site $site) => strtolower(Str::ascii($site->display_domain, 'de')), SORT_NATURAL)
            ->groupBy(fn (Site $site) => $site->owner());
        $groups = collect([Site::OWNER_ADMIN, Site::OWNER_UNASSIGNED, Site::OWNER_CUSTOMER])
            ->map(fn (string $owner) => $sites->get($owner, collect())->values())
            ->filter(fn ($group) => $group->isNotEmpty())
            ->values();

        return view('sites.index', [
            'sites' => $groups->flatten(1),
            'groups' => $groups->all(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Site::class);

        return view('sites.create', [
            'servers' => Server::orderBy('name')->get(),
            'customers' => Customer::orderBy('number')->get(),
            'preselected' => request()->query('customer'),
            // Creating DNS records needs a Hetzner token (E-116, E-118).
            'dnsAvailable' => HetznerCloud::dnsConfigured() && request()->user()->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Site::class);

        $servers = Server::orderBy('name')->get();
        if ($servers->isEmpty()) {
            return back()->with('error', __('sites.no_server'));
        }

        $domain = null;
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail) use (&$domain) {
                $domain = Domain::normalize((string) $value);

                if ($domain === null) {
                    $fail(__('sites.validation.domain_invalid'));
                } elseif ($domain === self::panelDomain()) {
                    $fail(__('sites.validation.domain_panel'));
                } elseif (Site::nameTaken($domain)) {
                    $fail(__('sites.validation.domain_taken'));
                }
            }],
            'www' => ['sometimes', 'boolean'],
            // Only asked for when there is more than one server.
            'server' => [Rule::requiredIf($servers->count() > 1), 'nullable', 'string', Rule::in($servers->modelKeys())],
            'tls' => ['sometimes', 'boolean'],
            'customer' => self::ownerRule(),
            'dns' => ['sometimes', 'boolean'],
            'php' => ['sometimes', 'nullable', 'string', Rule::in(config('owpanel.php_versions'))],
        ]);

        $server = $servers->count() > 1 ? $servers->find($validated['server']) : $servers->first();
        // PHP version (E-172): one the chosen server has, else its default.
        $php = $validated['php'] ?? null;
        if ($php !== null && ! in_array($php, $server->phpVersions(), true)) {
            return back()->withInput()->withErrors(['php' => __('sites.php.not_installed', ['version' => $php])]);
        }
        $php ??= $server->defaultPhpVersion();
        $tls = (bool) ($validated['tls'] ?? false);
        // www.<domain> too (E-110), if possible and free.
        $www = (bool) ($validated['www'] ?? false);
        if ($www && ! Site::wwwOffered($domain)) {
            return back()->withInput()->withErrors(['www' => __('sites.www.subdomain')]);
        }
        if ($www && (Site::nameTaken('www.'.$domain) || 'www.'.$domain === self::panelDomain())) {
            return back()->withInput()->withErrors(['www' => __('sites.www.unavailable', ['name' => Domain::display('www.'.$domain)])]);
        }

        [$customer, $adminOwned] = self::owner($validated['customer'] ?? null);
        if ($customer !== null
            && ($refused = $this->checkPackageLimits($request, $customer, PackageLimits::forSite($customer), 'site_created', ['domain' => $domain]))) {
            return $refused;
        }

        $site = DB::transaction(function () use ($request, $server, $domain, $tls, $www, $php, $customer, $adminOwned) {
            $site = new Site;
            $site->forceFill([
                'server_id' => $server->getKey(),
                'customer_id' => $customer?->getKey(),
                'admin_owned' => $adminOwned,
                'domain' => $domain,
                'type' => Site::TYPE_PHP,
                'system_user' => Site::freeSystemUser(),
                'wants_tls' => $tls,
                'www' => $www,
                // The chosen version; site-create reports the one it set up.
                'php_version' => $php,
                'status' => SiteStatus::Pending,
            ])->save();

            Audit::record('site.created', $site, [
                'domain' => $site->domain,
                'system_user' => $site->system_user,
                'server' => $server->getKey(),
            ], $request->user());

            return $site;
        });

        // DNS records first (E-116), so the certificate can be issued
        // right away. Only missing records are created.
        $dnsNote = null;
        if (($validated['dns'] ?? false) && HetznerCloud::dnsConfigured() && $request->user()->can('manageDns', $site)) {
            $dnsNote = $this->createDnsRecords($site, $request->user());
        }

        $task = DB::transaction(fn () => ServerTask::start(
            $server,
            ScriptCall::siteCreate($site->domain, $site->system_user, $tls, $www, php: $php, settings: PhpSettings::effective($site)),
            $request->user(),
            $site,
        ));

        // The overview of the new website waits for the task (E-152); a note
        // about DNS records written goes along.
        $redirect = redirect()->route('sites.show', $site);

        return $dnsNote !== '' ? $redirect->with('status', $dnsNote) : $redirect;
    }

    /**
     * @return string Summary for the message after creating a website.
     */
    private function createDnsRecords(Site $site, User $user): string
    {
        try {
            $result = app(SiteDnsWriter::class)->createMissing($site->load('server'), $user);
        } catch (\Throwable $e) {
            report($e);

            return __('dns.create.failed', ['error' => __('dns.errors.action_failed')]);
        }
        if ($result === null) {
            return __('dns.create.no_zone');
        }
        if ($result['error'] !== null) {
            return __('dns.create.failed', ['error' => __(Lang::has('dns.errors.'.$result['error']) ? 'dns.errors.'.$result['error'] : 'snapshots.errors.'.$result['error'])]);
        }

        return trim(trans_choice('dns.create.done', $result['done'], ['count' => $result['done']])
            .' '.($result['different'] > 0 ? trans_choice('dns.create.different', $result['different'], ['count' => $result['different']]) : ''));
    }

    /**
     * Assigns the website to a customer, to the administration or to nobody
     * (E-084, E-139). Changes nothing on the server.
     */
    public function assignCustomer(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('assignCustomer', $site);

        $validated = $request->validate([
            'customer' => self::ownerRule(),
        ]);

        [$customer, $adminOwned] = self::owner($validated['customer'] ?? null);
        if ($customer !== null
            && ($refused = $this->checkPackageLimits($request, $customer, PackageLimits::forSite($customer, $site), 'site_assigned', ['domain' => $site->domain]))) {
            return $refused;
        }

        $site->forceFill(['customer_id' => $customer?->getKey(), 'admin_owned' => $adminOwned])->save();

        // Only IDs: names of customers do not belong in the audit log.
        Audit::record('site.customer_assigned', $site, [
            'domain' => $site->domain,
            'customer_id' => $site->customer_id,
            'owner' => $site->owner(),
        ], $request->user());

        return redirect()->route('sites.domain.show', $site)->with('status', match ($site->owner()) {
            Site::OWNER_CUSTOMER => __('sites.customer.toast_assigned', ['name' => $customer->display_name]),
            Site::OWNER_ADMIN => __('sites.customer.toast_admin'),
            default => __('sites.customer.toast_removed'),
        });
    }

    /**
     * The owner field of the forms: empty for unassigned, "admin" for the
     * administration or the ID of a customer (E-139).
     *
     * @return list<mixed>
     */
    private static function ownerRule(): array
    {
        return ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
            if ($value !== Site::OWNER_ADMIN && ! Customer::whereKey($value)->exists()) {
                $fail(__('validation.exists', ['attribute' => __('sites.customer.label')]));
            }
        }];
    }

    /**
     * @return array{0: Customer|null, 1: bool} Customer and whether the website belongs to the administration.
     */
    private static function owner(?string $value): array
    {
        return match ($value) {
            null, '' => [null, false],
            Site::OWNER_ADMIN => [null, true],
            default => [Customer::findOrFail($value), false],
        };
    }

    public function show(Site $site): View
    {
        Gate::authorize('view', $site);

        return view('sites.show', [
            'site' => $site->load(['server', 'customer'])->loadCount('databases'),
            // For the state banner; all tasks are under "Logs & tasks" (E-086).
            'lastTask' => $site->tasks()->latest('created_at')->latest('id')->first(),
            'busy' => $site->isBusy(),
            'showServer' => Server::count() > 1,
        ]);
    }

    /**
     * "Website and domain": domain, www address and customer of a website
     * on one page of the website's left menu (E-137).
     */
    public function domainPage(Site $site): View
    {
        Gate::authorize('view', $site);
        abort_unless(Gate::any(['changeDomain', 'requestCertificate', 'assignCustomer'], $site), 403);

        return view('sites.domain', [
            'site' => $site,
            'busy' => $site->isBusy(),
            'customers' => Customer::orderBy('number')->get(),
        ]);
    }

    /**
     * PHP version and settings of the website (E-172, E-173), for both areas.
     */
    public function phpPage(Site $site): View
    {
        Gate::authorize('changePhp', $site);

        return view('sites.php', [
            'site' => $site->load(['server', 'customer.package']),
            'busy' => $site->isBusy(),
        ]);
    }

    public function certificatePage(Site $site): View
    {
        Gate::authorize('view', $site);

        return view('sites.certificate', [
            'site' => $site->load('server'),
            'lastTask' => $site->tasks()->latest('created_at')->latest('id')->first(),
            'busy' => $site->isBusy(),
        ]);
    }

    /**
     * Runs site-create again after it failed, e.g. because the DNS entry
     * was not ready for Let's Encrypt.
     */
    public function retry(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('retry', $site);

        if (! $site->canRetryCreate()) {
            return back()->with('error', __('sites.cannot_retry'));
        }

        $task = DB::transaction(function () use ($request, $site) {
            $site->forceFill(['status' => SiteStatus::Pending])->save();

            return ServerTask::start(
                $site->server,
                ScriptCall::siteCreate($site->domain, $site->system_user, $site->wants_tls, $site->www, php: $site->server->phpFor($site), settings: PhpSettings::effective($site)),
                $request->user(),
                $site,
            );
        });

        return redirect()->route('sites.show', $site);
    }

    /**
     * Requests a certificate for a site that runs on HTTP only: site-create
     * again with --tls (E-076). If Let's Encrypt refuses, the site stays as
     * it was.
     */
    public function requestCertificate(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('requestCertificate', $site);

        if (! $site->canRequestCertificate() && ! $site->canRequestWwwCertificate()) {
            return back()->with('error', __('sites.certificate.cannot_request'));
        }

        $task = DB::transaction(function () use ($request, $site) {
            $site->forceFill(['wants_tls' => true])->save();

            Audit::record('site.certificate_requested', $site, ['domain' => $site->domain], $request->user());

            return ServerTask::start(
                $site->server,
                ScriptCall::siteCreate($site->domain, $site->system_user, true, $site->www),
                $request->user(),
                $site,
            );
        });

        return $this->afterTask($request, $site);
    }

    /**
     * Turns www.<domain> on or off for an active website (E-110): site-create
     * again with or without --www. With a certificate, a new one is
     * requested at once that covers both names, or the domain alone.
     */
    public function updateWww(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('requestCertificate', $site);
        $www = $request->validate(['www' => ['required', 'boolean']])['www'];
        $www = (bool) $www;

        if ($site->status !== SiteStatus::Active || $site->hasOpenTasks()) {
            return back()->with('error', __('sites.www.busy'));
        }
        if ($www && ! Site::wwwOffered($site->domain)) {
            return back()->with('error', __('sites.www.subdomain'));
        }
        if ($www && (Site::nameTaken('www.'.$site->domain, $site) || 'www.'.$site->domain === self::panelDomain())) {
            return back()->with('error', __('sites.www.unavailable', ['name' => Domain::display('www.'.$site->domain)]));
        }
        if ($www === $site->www) {
            return back();
        }

        $task = DB::transaction(function () use ($request, $site, $www) {
            $site->forceFill(['www' => $www, 'www_tls' => false])->save();
            Audit::record($www ? 'site.www_enabled' : 'site.www_disabled', $site, ['domain' => $site->domain], $request->user());

            return ServerTask::start(
                $site->server,
                ScriptCall::siteCreate($site->domain, $site->system_user, $site->tls || $site->wants_tls, $www),
                $request->user(),
                $site,
            );
        });

        return $this->afterTask($request, $site);
    }

    /**
     * Switches the PHP version of the website (E-172), for administrators
     * and the customer of the website. site-create moves the pool to the
     * other PHP-FPM; the website pauses for about a second. Cron jobs are
     * written again afterwards, so their php is the new version too. The
     * version shown changes when the server reports it.
     */
    public function updatePhp(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('changePhp', $site);
        $php = $request->validate(['php' => ['required', 'string', Rule::in(config('owpanel.php_versions'))]])['php'];

        if ($site->status !== SiteStatus::Active || $site->hasOpenTasks()) {
            return back()->with('error', __('sites.php.busy'));
        }
        if (! in_array($php, $site->server->phpVersions(), true)) {
            return back()->with('error', __('sites.php.not_installed', ['version' => $php]));
        }
        if ($php === $site->php_version) {
            return back();
        }

        DB::transaction(function () use ($request, $site, $php) {
            Audit::record('site.php_changed', $site, ['domain' => $site->domain, 'from' => $site->php_version, 'to' => $php], $request->user());
            $task = ServerTask::start(
                $site->server,
                ScriptCall::siteCreate($site->domain, $site->system_user, $site->tls || $site->wants_tls, $site->www, php: $php),
                $request->user(),
                $site,
            );
            if ($site->cronJobs()->exists()) {
                ServerTask::start($site->server, ScriptCall::siteCron($site->system_user, []), $request->user(), $site, after: $task);
            }
        });

        return $this->afterTask($request, $site);
    }

    /**
     * PHP settings of the website (E-173): memory, upload size, run time and
     * whether errors are shown, within the package of the customer. A
     * customer without a package keeps the defaults (E-125).
     */
    public function updatePhpSettings(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('changePhp', $site);
        if (! $request->user()->isAdmin() && ! PhpSettings::customerMayChange($site)) {
            return back()->with('error', __('sites.php_settings.no_package'));
        }

        $bounds = PhpSettings::bounds($site);
        $rules = ['errors' => ['sometimes', 'boolean']];
        foreach (array_keys(PhpSettings::CHOSEN) as $key) {
            $rules[$key] = ['required', 'integer', 'min:'.$bounds[$key]['min'], 'max:'.$bounds[$key]['max']];
        }
        $validated = $request->validate($rules);

        if ($site->status !== SiteStatus::Active || $site->hasOpenTasks()) {
            return back()->with('error', __('sites.php.busy'));
        }

        $values = ['php_display_errors' => (bool) ($validated['errors'] ?? false)];
        foreach (PhpSettings::CHOSEN as $key => $columns) {
            $values[$columns['site']] = (int) $validated[$key];
        }
        $site->forceFill($values);
        $changed = array_keys($site->getDirty());
        if ($changed === []) {
            return back();
        }

        DB::transaction(function () use ($request, $site, $changed) {
            $site->save();
            Audit::record('site.php_settings_changed', $site, ['domain' => $site->domain, 'changed' => $changed], $request->user());
            ServerTask::start(
                $site->server,
                ScriptCall::siteCreate($site->domain, $site->system_user, $site->tls || $site->wants_tls, $site->www, settings: PhpSettings::effective($site)),
                $request->user(),
                $site,
            );
        });

        return $this->afterTask($request, $site);
    }

    /**
     * Both areas stay on the page the change was started from (E-152); the
     * task watcher reloads it when the task is done.
     */
    private function afterTask(Request $request, Site $site): RedirectResponse
    {
        return redirect()->back(fallback: route(Area::prefix().'sites.show', $site));
    }

    /**
     * Moves the site to another domain (E-112). Files, databases, SFTP,
     * cron jobs and backups stay, they belong to the site user. The panel
     * takes over the new domain only when site-create reports it; until
     * then, and if the certificate for the new domain is refused, the site
     * stays at its old domain.
     */
    public function updateDomain(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('changeDomain', $site);

        $domain = null;
        $request->validate([
            'new_domain' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail) use (&$domain, $site) {
                $domain = Domain::normalize((string) $value);

                if ($domain === null) {
                    $fail(__('sites.validation.domain_invalid'));
                } elseif ($domain === $site->domain) {
                    $fail(__('sites.domain.same'));
                } elseif ($domain === self::panelDomain()) {
                    $fail(__('sites.validation.domain_panel'));
                } elseif (Site::nameTaken($domain, $site)) {
                    $fail(__('sites.validation.domain_taken'));
                }
            }],
        ]);

        if ($site->status !== SiteStatus::Active || $site->hasOpenTasks()) {
            return back()->with('error', __('sites.www.busy'));
        }

        // www stays if the new domain allows it; www.<new> must be free.
        $www = $site->www && Site::wwwOffered($domain);
        if ($www && (Site::nameTaken('www.'.$domain, $site) || 'www.'.$domain === self::panelDomain())) {
            return back()->withInput()->withErrors(['new_domain' => __('sites.www.unavailable', ['name' => Domain::display('www.'.$domain)])]);
        }

        $task = DB::transaction(function () use ($request, $site, $domain, $www) {
            Audit::record('site.domain_change_started', $site, ['from' => $site->domain, 'to' => $domain], $request->user());

            return ServerTask::start(
                $site->server,
                ScriptCall::siteCreate($domain, $site->system_user, $site->tls || $site->wants_tls, $www, $site->domain),
                $request->user(),
                $site,
            );
        });

        return redirect()->route('sites.show', $site);
    }

    /**
     * Deletes the site with all its files. The domain must be typed in to
     * confirm (E-038).
     */
    /**
     * Confirmation before a website is deleted: what goes away and, for
     * administrators, which DNS records the panel set and may remove.
     */
    public function confirmDestroy(Site $site, SiteDnsWriter $dns): View|RedirectResponse
    {
        Gate::authorize('delete', $site);
        if ($site->isBusy()) {
            return redirect()->route('sites.show', $site)->with('error', __('sites.busy'));
        }

        return view('sites.delete', [
            'site' => $site->loadCount('databases'),
            'dnsRecords' => Gate::allows('manageDns', $site) ? $dns->owned($site) : [],
        ]);
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('delete', $site);

        $validated = $request->validate([
            'confirm_domain' => ['required', 'string', 'max:253'],
            'delete_dns' => ['sometimes', 'boolean'],
        ]);

        if (Domain::normalize($validated['confirm_domain']) !== $site->domain) {
            throw ValidationException::withMessages([
                'confirm_domain' => __('sites.validation.confirm_mismatch'),
            ]);
        }

        if ($site->isBusy()) {
            return back()->with('error', __('sites.busy'));
        }

        // Remove the panel's DNS records after the server deleted the
        // website (E-116), only when asked to.
        $deleteDns = (bool) ($validated['delete_dns'] ?? false) && $request->user()->can('manageDns', $site);

        $task = DB::transaction(function () use ($request, $site, $deleteDns) {
            $site->forceFill(['status' => SiteStatus::Deleting, 'delete_dns_records' => $deleteDns])->save();

            Audit::record('site.deletion_started', $site, [
                'domain' => $site->domain,
                'system_user' => $site->system_user,
                'delete_dns' => $deleteDns,
            ], $request->user());

            return ServerTask::start(
                $site->server,
                ScriptCall::siteDelete($site->system_user),
                $request->user(),
                $site,
            );
        });

        // The list shows the website as being deleted until the task is done.
        return redirect()->route('sites.index');
    }

    /**
     * The panel's own domain belongs to ow10000 and cannot become a site.
     */
    private static function panelDomain(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }
}
