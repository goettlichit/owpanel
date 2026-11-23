<?php

namespace App\Http\Controllers;

use App\Customers\StorageQuota;
use App\Dns\SiteDns;
use App\Enums\TaskStatus;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Customer area, "My websites" (E-124, E-143): the customer's own websites
 * with an overview (domain, www, certificate) and the DNS check. SFTP and
 * logs share their pages with the administration (My\SiteSftpController,
 * My\SiteLogController). Every page checks the website through SitePolicy,
 * so a customer reaches only his own.
 */
class MySitesController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()->customer->load(['package', 'sites' => fn ($query) => $query->orderBy('domain')]);

        return view('my.sites', [
            'sites' => $customer->sites,
            'package' => $customer->package,
            'storage' => StorageQuota::summary(StorageQuota::usedBytes($customer), StorageQuota::limitBytes($customer)),
            'measured' => $customer->sites->max('usage_measured_at'),
        ]);
    }

    public function show(Site $site): View
    {
        Gate::authorize('view', $site);

        return view('my.sites.show', [
            'site' => $site->loadCount('databases'),
            'busy' => $site->isBusy(),
            // Only whether the last change failed; the task log with its
            // commands stays with the administration.
            'failed' => $site->tasks()->latest('created_at')->latest('id')->first()?->status === TaskStatus::Failed,
        ]);
    }

    /**
     * DNS check without changes (E-124): the addresses the website needs and
     * what the name servers answer. No Hetzner zone, no records to set.
     */
    public function dns(Site $site, SiteDns $dns): View
    {
        Gate::authorize('checkDns', $site);

        return view('my.sites.dns', [
            'site' => $site,
            'dns' => $dns->check($site->load('server'), fresh: (bool) session('dns.fresh'), withZone: false),
        ]);
    }

    public function checkDns(Site $site): RedirectResponse
    {
        Gate::authorize('checkDns', $site);

        return redirect()->route('my.sites.dns', $site)->with('dns.fresh', true)->with('status', __('dns.checked'));
    }
}
