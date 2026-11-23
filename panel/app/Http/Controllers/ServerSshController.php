<?php

namespace App\Http\Controllers;

use App\Enums\ServerScript;
use App\Enums\TaskStatus;
use App\Models\Server;
use App\Models\ServerTask;
use App\Servers\ScriptCall;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * SSH login of a server, for administrators (E-155): password login on or
 * off, and which login accounts have a key. Turning it off is refused on
 * the server while an account has none.
 */
class ServerSshController extends Controller
{
    public function show(Server $server): View
    {
        Gate::authorize('manageSsh', $server);

        return view('servers.ssh', ['server' => $server]);
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageSsh', $server);
        $validated = $request->validate(['password_login' => ['required', 'in:on,off']]);
        $running = $server->tasks()->where('script', ServerScript::ServerSsh->value)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Running->value])->exists();
        if ($running) {
            return back()->with('error', __('ssh.busy'));
        }

        $allowed = $validated['password_login'] === 'on';
        ServerTask::start($server, ScriptCall::sshPasswordLogin($allowed), $request->user());
        Audit::record('server.ssh_password_login', $server, ['allowed' => $allowed], $request->user());

        return redirect()->route('servers.ssh', $server);
    }
}
