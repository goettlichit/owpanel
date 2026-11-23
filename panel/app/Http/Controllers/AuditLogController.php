<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Database;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Pages of the objects an entry can refer to, by class name.
     */
    private const SUBJECT_ROUTES = [
        'Site' => [Site::class, 'sites.show'],
        'Server' => [Server::class, 'servers.show'],
        'Customer' => [Customer::class, 'customers.show'],
        'Package' => [Package::class, 'packages.edit'],
        'Database' => [Database::class, 'databases.show'],
        'ServerTask' => [ServerTask::class, 'tasks.show'],
    ];

    public function index(): View
    {
        Gate::authorize('viewAny', AuditLog::class);

        return view('audit-log.index', [
            'entries' => AuditLog::with('user')->latest('created_at')->latest('id')->paginate(50),
        ]);
    }

    /**
     * One entry with everything that was recorded: object, details,
     * address and browser.
     */
    public function show(string $entry): View
    {
        Gate::authorize('viewAny', AuditLog::class);
        $entry = AuditLog::with('user')->findOrFail($entry);

        return view('audit-log.show', [
            'entry' => $entry,
            'subjectUrl' => $this->subjectUrl($entry),
        ]);
    }

    /**
     * Link to the object, if it still exists and the user may see it.
     */
    private function subjectUrl(AuditLog $entry): ?string
    {
        [$class, $route] = self::SUBJECT_ROUTES[$entry->subject_type] ?? [null, null];
        if ($class === null || $entry->subject_id === null) {
            return null;
        }
        $subject = $class::find($entry->subject_id);

        return $subject !== null && Gate::allows('view', $subject) ? route($route, $subject) : null;
    }
}
