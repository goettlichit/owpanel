<?php

namespace App\Http\Controllers;

use App\Customers\PackageLimits;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Http\Controllers\Concerns\ChecksPackageLimits;
use App\Models\CronJob;
use App\Models\ServerTask;
use App\Models\Site;
use App\Servers\ScriptCall;
use App\Support\Area;
use App\Support\Audit;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Cron jobs of a website (E-088). The panel keeps the jobs; every change
 * starts the task site-cron, which writes all active jobs of the website
 * to the server. The page stays open, the toast and the state line show
 * how the transfer went.
 */
class SiteCronController extends Controller
{
    use ChecksPackageLimits;

    public function index(Site $site): View
    {
        Gate::authorize('manageCron', $site);

        return view('sites.cron', [
            'site' => $site->load(['server', 'customer.package']),
            'jobs' => $site->cronJobs()->orderBy('created_at')->get(),
            'sync' => $site->tasks()->where('script', ServerScript::SiteCron->value)->latest('created_at')->latest('id')->first(),
            'timezone' => config('owpanel.timezone'),
            // Customers see a disabled form with the reason (E-146).
            'blocked' => Area::customer() ? PackageLimits::customerBlock($site, 'cron') : null,
        ]);
    }

    public function store(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manageCron', $site);

        if ($site->cronJobs()->count() >= CronJob::MAX_PER_SITE) {
            return back()->with('error', __('sites.cron.too_many', ['count' => CronJob::MAX_PER_SITE]));
        }

        $validated = $this->validated($request);
        if ($refused = $this->checkPackageLimits($request, $site->customer, PackageLimits::forCronJob($site, $validated['schedule']), 'cron_created', ['domain' => $site->domain])) {
            return $refused;
        }

        $job = $site->cronJobs()->create($validated + ['enabled' => true]);
        Audit::record('site.cron_created', $site, ['domain' => $site->domain, 'cron_job' => $job->id], $request->user());

        return $this->sync($request, $site, __('sites.cron.toast.created'));
    }

    public function update(Request $request, Site $site, CronJob $cronJob): RedirectResponse
    {
        Gate::authorize('manageCron', $site);
        abort_unless($cronJob->site_id === $site->id, 404);

        // A job an administrator allowed beyond the package can only be
        // deleted by the customer (E-139).
        if (! $request->user()->isAdmin() && ! PackageLimits::scheduleAllowed(PackageLimits::package($site), $cronJob->schedule)) {
            return back()->with('error', __('packages.limit_broken.cron_locked'));
        }

        if ($request->has('toggle')) {
            $cronJob->update(['enabled' => ! $cronJob->enabled]);
        } else {
            $validated = $this->validated($request, 'edit_');
            // Only a changed schedule is checked against the package.
            if ($validated['schedule'] !== $cronJob->schedule
                && ($refused = $this->checkPackageLimits($request, $site->customer, PackageLimits::forCronJob($site, $validated['schedule'], $cronJob), 'cron_updated', ['domain' => $site->domain]))) {
                return $refused;
            }
            $cronJob->update($validated);
        }
        Audit::record('site.cron_updated', $site, ['domain' => $site->domain, 'cron_job' => $cronJob->id], $request->user());

        return $this->sync($request, $site, __('sites.cron.toast.updated'));
    }

    public function destroy(Request $request, Site $site, CronJob $cronJob): RedirectResponse
    {
        Gate::authorize('manageCron', $site);
        abort_unless($cronJob->site_id === $site->id, 404);

        $cronJob->delete();
        Audit::record('site.cron_deleted', $site, ['domain' => $site->domain, 'cron_job' => $cronJob->id], $request->user());

        return $this->sync($request, $site, __('sites.cron.toast.deleted'));
    }

    /**
     * Starts site-cron unless the website is not set up yet; then the jobs
     * wait in the panel and site-create's success writes them.
     */
    private function sync(Request $request, Site $site, string $message): RedirectResponse
    {
        if ($site->status === SiteStatus::Active) {
            ServerTask::start($site->server, ScriptCall::siteCron($site->system_user, []), $request->user(), $site);
        } else {
            $message .= ' '.__('sites.cron.toast.later');
        }

        return redirect()->route(Area::prefix().'sites.cron', $site)->with('status', $message);
    }

    /**
     * @return array{schedule: string, command: string, description: string|null}
     */
    private function validated(Request $request, string $prefix = ''): array
    {
        $validated = $request->validate([
            $prefix.'schedule' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! CronJob::validSchedule($value)) {
                    $fail(__('sites.cron.validation.schedule'));
                }
            }],
            $prefix.'command' => ['required', 'string', 'max:'.CronJob::MAX_COMMAND, function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! CronJob::validCommand($value)) {
                    $fail(__('sites.cron.validation.command'));
                }
            }],
            $prefix.'description' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'schedule' => CronJob::normalizeSchedule($validated[$prefix.'schedule']),
            'command' => trim($validated[$prefix.'command']),
            'description' => filled($validated[$prefix.'description'] ?? null) ? trim($validated[$prefix.'description']) : null,
        ];
    }
}
