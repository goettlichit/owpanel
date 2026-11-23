<?php

namespace App\Http\Controllers;

use App\Customers\PackageLimits;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Http\Controllers\Concerns\ChecksPackageLimits;
use App\Models\Database;
use App\Models\ServerTask;
use App\Models\Site;
use App\Servers\ScriptCall;
use App\Support\Area;
use App\Support\Audit;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Databases of a website (E-078). Creating, changing the password and
 * deleting only queue db-create and db-delete; the password reaches the
 * server on stdin when the worker runs the task.
 */
class DatabaseController extends Controller
{
    use ChecksPackageLimits;

    public function index(Site $site): View
    {
        Gate::authorize('viewAny', [Database::class, $site]);

        return view('databases.index', [
            'site' => $site->load(['server', 'customer.package']),
            'databases' => $site->databases()->orderBy('name')->get(),
            // Customers see a disabled form with the reason (E-146).
            'blocked' => Area::customer() ? PackageLimits::customerBlock($site, 'database') : null,
        ]);
    }

    public function store(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('create', [Database::class, $site]);

        if ($site->status !== SiteStatus::Active || $site->isBusy()) {
            return back()->with('error', __('databases.site_not_ready'));
        }

        $validated = $request->validate([
            'suffix' => ['required', 'string', 'max:16', 'regex:'.Database::SUFFIX_PATTERN, function (string $attribute, mixed $value, Closure $fail) use ($site) {
                if (Database::where('name', $site->system_user.'_'.$value)->exists()) {
                    $fail(__('databases.validation.taken'));
                }
            }],
        ], [
            'suffix.regex' => __('databases.validation.suffix'),
        ]);

        if ($refused = $this->checkPackageLimits($request, $site->customer, PackageLimits::forDatabase($site), 'database_created', ['domain' => $site->domain])) {
            return $refused;
        }

        $name = $site->system_user.'_'.$validated['suffix'];

        $task = DB::transaction(function () use ($request, $site, $name) {
            $database = new Database;
            $database->forceFill([
                'server_id' => $site->server_id,
                'site_id' => $site->getKey(),
                'name' => $name,
                'password' => Database::generatePassword(),
                'status' => SiteStatus::Pending,
            ])->save();

            Audit::record('database.created', $database, ['database' => $name, 'site' => $site->getKey()], $request->user());

            return ServerTask::start($site->server, ScriptCall::dbCreate($name), $request->user(), $site, $database);
        });

        return Area::afterTask('my.databases.index', $site);
    }

    public function show(Database $database): View
    {
        Gate::authorize('view', $database);

        return $this->detail($database, revealed: false);
    }

    /**
     * Shows the password in plain text, recorded in the audit log (E-135:
     * no second password prompt).
     */
    public function reveal(Request $request, Database $database): View
    {
        Gate::authorize('revealPassword', $database);

        Audit::record('database.password_revealed', $database, ['database' => $database->name], $request->user());

        return $this->detail($database, revealed: true);
    }

    /**
     * Runs db-create again after it failed.
     */
    public function retry(Request $request, Database $database): RedirectResponse
    {
        Gate::authorize('retry', $database);

        if ($database->status !== SiteStatus::Failed || $database->isBusy()
            || $database->tasks()->latest('created_at')->latest('id')->first()?->script !== ServerScript::DbCreate) {
            return back()->with('error', __('databases.busy'));
        }

        $task = DB::transaction(function () use ($request, $database) {
            $database->forceFill(['status' => SiteStatus::Pending])->save();

            return ServerTask::start($database->server, ScriptCall::dbCreate($database->name), $request->user(), $database->site, $database);
        });

        return redirect()->back(fallback: route('databases.show', $database));
    }

    /**
     * Sets a new random password. The old one stays valid until db-create
     * has confirmed the new one.
     */
    public function changePassword(Request $request, Database $database): RedirectResponse
    {
        Gate::authorize('changePassword', $database);

        if ($database->status !== SiteStatus::Active || $database->isBusy()) {
            return back()->with('error', __('databases.busy'));
        }

        $task = DB::transaction(function () use ($request, $database) {
            $database->forceFill(['new_password' => Database::generatePassword()])->save();

            Audit::record('database.password_changed', $database, ['database' => $database->name], $request->user());

            return ServerTask::start($database->server, ScriptCall::dbCreate($database->name), $request->user(), $database->site, $database);
        });

        return Area::afterTask('my.databases.show', $database);
    }

    /**
     * Deletes the database and its user for good after the name has been
     * typed in (E-038).
     */
    public function destroy(Request $request, Database $database): RedirectResponse
    {
        Gate::authorize('delete', $database);

        $validated = $request->validate(['confirm_name' => ['required', 'string', 'max:24']]);

        if (trim($validated['confirm_name']) !== $database->name) {
            throw ValidationException::withMessages(['confirm_name' => __('databases.validation.confirm_mismatch')]);
        }

        if ($database->isBusy()) {
            return back()->with('error', __('databases.busy'));
        }

        $task = DB::transaction(function () use ($request, $database) {
            $database->forceFill(['status' => SiteStatus::Deleting])->save();

            Audit::record('database.deletion_started', $database, ['database' => $database->name], $request->user());

            return ServerTask::start($database->server, ScriptCall::dbDelete($database->name), $request->user(), $database->site, $database);
        });

        return Area::afterTask('my.databases.index', $database->site);
    }

    private function detail(Database $database, bool $revealed): View
    {
        $database->load('site.server');

        return view('databases.show', [
            'database' => $database,
            'site' => $database->site,
            'revealed' => $revealed,
            'busy' => $database->isBusy(),
            'lastTask' => $database->tasks()->with(['server', 'user', 'site'])->latest('created_at')->latest('id')->first(),
            'tasks' => $database->tasks()->with(['server', 'user', 'site'])->latest('created_at')->latest('id')->limit(5)->get(),
        ]);
    }
}
