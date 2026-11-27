<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\ServerJoinToken;
use App\Servers\ScriptsArchive;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Server -> Add server (E-166, E-196): the administrator names the new
 * server and gets a one-time code and the command for it. The code is shown
 * once, right after it was made.
 */
class ServerJoinController extends Controller
{
    public function create(Request $request): View
    {
        Gate::authorize('create', Server::class);
        $panel = Server::panelHost();

        return view('servers.join', [
            'panel' => $panel,
            'domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'ready' => $panel?->private_ipv4 !== null && ScriptsArchive::available(),
            'code' => $request->session()->get('join_code'),
            'name' => $request->session()->get('join_name'),
            'expires' => $request->session()->get('join_expires'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Server::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', Rule::unique('servers', 'name')],
        ]);
        [$token, $code] = ServerJoinToken::issue($validated['name'], $request->user());
        Audit::record('server.join_started', null, ['name' => $token->name], $request->user());

        // Shown once; the next page load no longer has it.
        return redirect()->route('servers.join')
            ->with('join_code', $code)->with('join_name', $token->name)->with('join_expires', $token->expires_at->toIso8601String());
    }
}
