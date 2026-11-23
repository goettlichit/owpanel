<?php

namespace App\Http\Controllers;

use App\Customers\PackageLimits;
use App\Databases\Transfers;
use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Enums\TaskStatus;
use App\Models\Database;
use App\Models\ServerTask;
use App\Servers\ScriptCall;
use App\Support\Area;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export and import of a database as an SQL file (E-082).
 *
 * Both are server tasks with status and log: the queue worker streams the
 * dump from the server into a file, or an uploaded file to the server. The
 * files stay in the transfer directory for one day at the most.
 */
class DatabaseTransferController extends Controller
{
    public function show(Database $database): View
    {
        Gate::authorize('export', $database);

        $exports = $database->tasks()
            ->where('script', ServerScript::DbExport->value)
            ->where('status', TaskStatus::Succeeded->value)
            ->where('finished_at', '>=', now()->subHours(Transfers::KEEP_HOURS))
            ->latest('finished_at')
            ->get()
            ->filter(fn (ServerTask $task) => Transfers::exists($task->transfer_file))
            ->values();

        return view('databases.transfer', [
            'database' => $database,
            'site' => $database->site->load('server'),
            'exports' => $exports,
            'busy' => $database->isBusy(),
            'maxUploadMb' => (int) config('owpanel.transfers.max_upload_mb'),
            // Customers see the import disabled with the reason (E-126, E-146).
            'importBlocked' => match (true) {
                $database->site?->isSuspended() === true => __('sites.suspension.blocked'),
                Area::customer() && PackageLimits::forStorage($database->site?->customer) !== [] => __('my.limits.storage'),
                default => null,
            },
        ]);
    }

    public function export(Request $request, Database $database): RedirectResponse
    {
        Gate::authorize('export', $database);

        if ($database->status !== SiteStatus::Active || $database->isBusy()) {
            return back()->with('error', __('databases.transfer.busy'));
        }

        Transfers::ensureDirectory();
        $file = Transfers::newName(gzip: true);
        // The password is read only by the worker (ServerTask::call()).
        $call = ScriptCall::dbExport($database->name, 'unused', Transfers::path($file));
        $task = ServerTask::start($database->server, $call, $request->user(), $database->site, $database, $file);

        Audit::record('database.export_started', $database, ['database' => $database->name], $request->user());

        return Area::afterTask('my.databases.transfer', $database);
    }

    public function import(Request $request, Database $database): RedirectResponse
    {
        Gate::authorize('import', $database);

        if ($database->status !== SiteStatus::Active || $database->isBusy()) {
            return back()->with('error', __('databases.transfer.busy'));
        }
        // A full customer imports nothing (E-126). Administrators are not
        // held back; an upload cannot go through the confirmation again.
        if (! $request->user()->isAdmin() && ($full = PackageLimits::forStorage($database->site?->customer)) !== []) {
            return back()->with('error', implode(' ', $full));
        }

        $validated = $request->validate([
            'dump' => ['required', 'file', 'max:'.((int) config('owpanel.transfers.max_upload_mb') * 1024)],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('databases.transfer.confirm_required'),
        ]);

        $upload = $validated['dump'];
        $head = (string) file_get_contents($upload->getRealPath(), length: 2);
        $gzip = $head === "\x1f\x8b";
        $name = strtolower($upload->getClientOriginalName());
        if (! $gzip && ! str_ends_with($name, '.sql')) {
            return back()->withErrors(['dump' => __('databases.transfer.wrong_type')]);
        }

        Transfers::ensureDirectory();
        $file = Transfers::newName($gzip);
        $upload->move(Transfers::directory(), $file);
        // The worker reads the file through the group (E-082).
        @chmod(Transfers::path($file), 0640);

        $bytes = (int) filesize(Transfers::path($file));
        $call = ScriptCall::dbImport($database->name, 'unused', Transfers::path($file), $gzip);
        $task = ServerTask::start($database->server, $call, $request->user(), $database->site, $database, $file);

        Audit::record('database.import_started', $database, [
            'database' => $database->name,
            'bytes' => $bytes,
        ], $request->user());

        return Area::afterTask('my.databases.transfer', $database);
    }

    public function download(Request $request, ServerTask $task): BinaryFileResponse
    {
        $database = $task->database;
        abort_if($database === null || $task->script !== ServerScript::DbExport, 404);
        Gate::authorize('export', $database);
        abort_unless($task->status === TaskStatus::Succeeded && Transfers::exists($task->transfer_file), 404);

        Audit::record('database.export_downloaded', $database, ['database' => $database->name], $request->user());

        $filename = $database->name.'-'.$task->finished_at?->copy()->setTimezone(config('owpanel.timezone'))->format('Y-m-d-His').'.sql.gz';

        return response()->download(Transfers::path((string) $task->transfer_file), $filename, [
            'Content-Type' => 'application/gzip',
        ]);
    }
}
