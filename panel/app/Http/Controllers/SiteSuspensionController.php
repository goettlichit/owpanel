<?php

namespace App\Http\Controllers;

use App\Customers\CustomerAccess;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Sites\SiteDeploy;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Suspends a website or lifts the suspension (E-127). The panel keeps the
 * state in its own columns and starts site-suspend or site-unsuspend; both
 * are repeatable, so a failed run is simply started again from here.
 */
class SiteSuspensionController extends Controller
{
    public function show(Site $site): View
    {
        Gate::authorize('suspend', $site);
        $account = $site->customer?->users()->first();

        return view('sites.suspension', [
            'site' => $site,
            'account' => $account,
            'lastTask' => $lastTask = $site->tasks()
                ->whereIn('script', [ServerScript::SiteSuspend->value, ServerScript::SiteUnsuspend->value])
                ->latest('id')->first(),
            // While it runs, the page shows the button that started it, with
            // its spinner (E-152), not already the one for the new state.
            'running' => $lastTask !== null && ! $lastTask->isFinished() ? $lastTask->script : null,
        ]);
    }

    public function store(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('suspend', $site);
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'lock_access' => ['nullable', 'boolean'],
        ]);
        if ($site->status !== SiteStatus::Active || $site->hasOpenTasks()) {
            return back()->with('error', __('sites.suspension.busy'));
        }

        $task = DB::transaction(function () use ($request, $site, $validated) {
            // A second run keeps date and note of the first one.
            if (! $site->isSuspended()) {
                $site->forceFill([
                    'suspended_at' => now(),
                    'suspended_by' => 'admin',
                    'suspend_note' => ($validated['note'] ?? '') !== '' ? $validated['note'] : null,
                ])->save();
                Audit::record('site.suspended', $site, ['domain' => $site->domain], $request->user());
            }
            $account = $site->customer?->users()->first();
            if ($request->boolean('lock_access') && $account !== null && ! $account->isLocked()) {
                CustomerAccess::lock($account, $request->user());
            }

            return SiteDeploy::suspend($site, $request->user());
        });

        return redirect()->route('sites.suspension', $site);
    }

    /**
     * Lifts the suspension. A locked access of the customer stays locked;
     * it is unlocked on the page of the customer.
     */
    public function destroy(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('suspend', $site);
        if (! $site->isSuspended()) {
            return redirect()->route('sites.suspension', $site);
        }
        if ($site->hasOpenTasks()) {
            return back()->with('error', __('sites.suspension.busy'));
        }

        $task = DB::transaction(function () use ($request, $site) {
            Audit::record('site.unsuspended', $site, ['domain' => $site->domain, 'by' => $site->suspended_by], $request->user());
            $site->forceFill(['suspended_at' => null, 'suspended_by' => null, 'suspend_note' => null])->save();

            return SiteDeploy::suspend($site, $request->user(), false);
        });

        return redirect()->route('sites.suspension', $site);
    }
}
