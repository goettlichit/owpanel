<?php

namespace App\Http\Controllers;

use App\Enums\ServerScript;
use App\Firewall\BlocklistFetcher;
use App\Firewall\BlocklistParser;
use App\Firewall\Blocklists;
use App\Models\Blocklist;
use App\Models\Fail2banIgnore;
use App\Models\FirewallRule;
use App\Models\Server;
use App\Models\ServerTask;
use App\Servers\ScriptCall;
use App\Support\Audit;
use App\Support\IpNetwork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Firewall of a server (E-090): fixed ports, own rules and fail2ban with
 * exceptions and current bans. Rule changes go through firewall-rules and
 * are confirmed over a new SSH connection, or rolled back after 60 seconds.
 * Blocklists (E-170) are fetched by the panel and loaded with
 * server-blocklist; the fail2ban exceptions are never blocked.
 */
class ServerFirewallController extends Controller
{
    public function index(Request $request, Server $server): View
    {
        Gate::authorize('manageFirewall', $server);

        $ignores = $server->fail2banIgnores()->orderBy('created_at')->orderBy('id')->get();
        $clientIp = IpNetwork::normalize((string) $request->ip());
        $blocklists = $server->blocklists()->orderBy('created_at')->orderBy('id')->get();

        return view('servers.firewall', [
            'server' => $server,
            'rules' => $server->firewallRules()->orderBy('created_at')->orderBy('id')->get(),
            'ignores' => $ignores,
            'clientIp' => $clientIp,
            'clientIgnored' => $clientIp !== null && $ignores->contains(fn (Fail2banIgnore $ignore) => IpNetwork::contains($ignore->address, $clientIp)),
            'rulesTask' => $this->latest($server, ServerScript::FirewallRules),
            'confirmTask' => $this->latest($server, ServerScript::FirewallConfirm),
            'fail2banTask' => $server->tasks()->where('script', ServerScript::ServerFail2ban->value)->latest('created_at')->latest('id')->first(),
            'blocklists' => $blocklists,
            'blocklistTask' => $this->latest($server, ServerScript::ServerBlocklist),
        ]);
    }

    public function storeRule(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);

        $validated = $request->validate([
            'protocol' => ['required', 'in:'.implode(',', FirewallRule::PROTOCOLS)],
            'ports' => ['required', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:50'],
            'comment' => ['nullable', 'string', 'max:100'],
        ]);
        $ports = FirewallRule::normalizePorts($validated['ports']);
        if ($ports === null) {
            throw ValidationException::withMessages(['ports' => __('firewall.validation.ports')]);
        }
        $source = null;
        if (trim((string) ($validated['source'] ?? '')) !== '') {
            $source = IpNetwork::normalize((string) $validated['source']);
            if ($source === null) {
                throw ValidationException::withMessages(['source' => __('firewall.validation.address')]);
            }
        }
        if ($server->firewallRules()->count() >= FirewallRule::MAX_PER_SERVER) {
            return back()->with('error', __('firewall.rules.too_many', ['count' => FirewallRule::MAX_PER_SERVER]));
        }

        $rule = $server->firewallRules()->create([
            'protocol' => $validated['protocol'],
            'ports' => $ports,
            'source' => $source,
            'comment' => trim((string) ($validated['comment'] ?? '')) ?: null,
        ]);
        Audit::record('server.firewall_rule_added', $server, ['rule' => $rule->getKey(), 'protocol' => $rule->protocol, 'ports' => $rule->ports], $request->user());

        return $this->applyRules($request, $server, __('firewall.toast.rule_added'));
    }

    public function destroyRule(Request $request, Server $server, FirewallRule $firewallRule): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);
        abort_unless($firewallRule->server_id === $server->getKey(), 404);

        $firewallRule->delete();
        Audit::record('server.firewall_rule_deleted', $server, ['rule' => $firewallRule->getKey(), 'protocol' => $firewallRule->protocol, 'ports' => $firewallRule->ports], $request->user());

        return $this->applyRules($request, $server, __('firewall.toast.rule_deleted'));
    }

    public function storeIgnore(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);

        $validated = $request->validate([
            'address' => ['required', 'string', 'max:50'],
            'comment' => ['nullable', 'string', 'max:100'],
        ]);
        $address = IpNetwork::normalize($validated['address']);
        if ($address === null) {
            throw ValidationException::withMessages(['address' => __('firewall.validation.address')]);
        }
        if ($server->fail2banIgnores()->where('address', $address)->exists()) {
            throw ValidationException::withMessages(['address' => __('firewall.validation.duplicate')]);
        }
        if ($server->fail2banIgnores()->count() >= Fail2banIgnore::MAX_PER_SERVER) {
            return back()->with('error', __('firewall.ignore.too_many', ['count' => Fail2banIgnore::MAX_PER_SERVER]));
        }

        $server->fail2banIgnores()->create([
            'address' => $address,
            'comment' => trim((string) ($validated['comment'] ?? '')) ?: null,
        ]);
        // Only that an exception was added; the address is personal data.
        Audit::record('server.fail2ban_ignore_added', $server, [], $request->user());

        return $this->applyIgnores($request, $server, __('firewall.toast.ignore_added'));
    }

    public function destroyIgnore(Request $request, Server $server, Fail2banIgnore $fail2banIgnore): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);
        abort_unless($fail2banIgnore->server_id === $server->getKey(), 404);

        $fail2banIgnore->delete();
        Audit::record('server.fail2ban_ignore_deleted', $server, [], $request->user());

        return $this->applyIgnores($request, $server, __('firewall.toast.ignore_deleted'));
    }

    public function unban(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);

        $address = IpNetwork::normalize((string) $request->validate(['ip' => ['required', 'string', 'max:50']])['ip']);
        if ($address === null || str_contains($address, '/')) {
            return back()->with('error', __('firewall.validation.address'));
        }

        ServerTask::start($server, ScriptCall::fail2banUnban($address), $request->user());
        Audit::record('server.fail2ban_unbanned', $server, [], $request->user());

        return redirect()->route('servers.firewall', $server)->with('status', __('firewall.toast.unbanned', ['ip' => $address]));
    }

    /**
     * Adds a blocklist: fetched and checked at once, so a wrong address or
     * format is reported right here (E-170).
     */
    public function storeBlocklist(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'url' => ['required', 'string', 'max:500'],
            'interval_hours' => ['required', 'integer', 'in:'.implode(',', Blocklist::INTERVALS)],
        ]);
        $url = trim($validated['url']);
        $name = $this->listName($validated['name'] ?? null);
        if ($server->blocklists()->where('url', $url)->exists()) {
            throw ValidationException::withMessages(['url' => __('firewall.blocklists.duplicate')]);
        }
        if ($server->blocklists()->count() >= Blocklist::MAX_PER_SERVER) {
            return back()->with('error', __('firewall.blocklists.too_many', ['count' => Blocklist::MAX_PER_SERVER]));
        }

        $result = BlocklistFetcher::fetch($url);
        if (! $result['ok']) {
            throw ValidationException::withMessages(['url' => Blocklist::describeError($result['error'], $result['detail'] ?? null)]);
        }
        $this->refuseOwnAddress($request, $server, $result['entries']);
        if (Blocklists::entriesBesides($server) + count($result['entries']) > Blocklist::MAX_ENTRIES_PER_SERVER) {
            throw ValidationException::withMessages(['url' => __('firewall.blocklists.too_many_entries', ['count' => Number::format(Blocklist::MAX_ENTRIES_PER_SERVER, locale: app()->getLocale())])]);
        }

        $list = $server->blocklists()->create(['name' => $name, 'url' => $url, 'interval_hours' => (int) $validated['interval_hours']]);
        $list->cache($result['entries']);
        $list->forceFill([
            'entries' => count($result['entries']),
            'ignored' => $result['ignored'],
            'content_hash' => $result['hash'],
            'checked_at' => now(),
            'changed_at' => now(),
        ])->save();
        Audit::record('server.blocklist_added', $server, ['blocklist' => $list->getKey(), 'name' => $name, 'url' => $url], $request->user());
        Blocklists::apply($server, $request->user());

        return redirect()->route('servers.firewall', $server)
            ->with('status', __('firewall.blocklists.added', ['count' => Number::format($list->entries, locale: app()->getLocale())]));
    }

    public function updateBlocklist(Request $request, Server $server, Blocklist $blocklist): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);
        abort_unless($blocklist->server_id === $server->getKey(), 404);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'interval_hours' => ['required', 'integer', 'in:'.implode(',', Blocklist::INTERVALS)],
        ]);
        $blocklist->update(['name' => $this->listName($validated['name'] ?? null), 'interval_hours' => (int) $validated['interval_hours']]);
        Audit::record('server.blocklist_changed', $server, ['blocklist' => $blocklist->getKey(), 'name' => $blocklist->name, 'interval_hours' => $blocklist->interval_hours], $request->user());

        return redirect()->route('servers.firewall', $server)->with('status', __('firewall.blocklists.saved'));
    }

    public function refreshBlocklist(Request $request, Server $server, Blocklist $blocklist): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);
        abort_unless($blocklist->server_id === $server->getKey(), 404);

        $result = Blocklists::refresh($blocklist);
        if (! $result['ok']) {
            return redirect()->route('servers.firewall', $server)
                ->with('error', __('firewall.blocklists.refresh_failed', ['reason' => Blocklist::describeError((string) $result['error'], $result['detail'])]));
        }
        // Loaded even when unchanged, so a refresh by hand also repairs the server.
        Blocklists::apply($server, $request->user());

        return redirect()->route('servers.firewall', $server)
            ->with('status', __($result['changed'] ? 'firewall.blocklists.refreshed' : 'firewall.blocklists.unchanged'));
    }

    public function destroyBlocklist(Request $request, Server $server, Blocklist $blocklist): RedirectResponse
    {
        Gate::authorize('manageFirewall', $server);
        abort_unless($blocklist->server_id === $server->getKey(), 404);

        $blocklist->delete();
        Audit::record('server.blocklist_deleted', $server, ['blocklist' => $blocklist->getKey(), 'url' => $blocklist->url], $request->user());
        Blocklists::apply($server, $request->user());

        return redirect()->route('servers.firewall', $server)->with('status', __('firewall.blocklists.deleted'));
    }

    /**
     * A name as entered, without control characters; empty means none.
     */
    private function listName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $name));

        return $name === '' ? null : $name;
    }

    /**
     * Stops a list that would lock out the administrator who adds it, unless
     * the address is a fail2ban exception and so never blocked.
     *
     * @param  list<string>  $entries
     */
    private function refuseOwnAddress(Request $request, Server $server, array $entries): void
    {
        $clientIp = IpNetwork::normalize((string) $request->ip());
        if ($clientIp === null || str_contains($clientIp, '/')) {
            return;
        }
        if ($server->fail2banIgnores()->get()->contains(fn (Fail2banIgnore $ignore) => IpNetwork::contains($ignore->address, $clientIp))) {
            return;
        }
        $entry = BlocklistParser::firstContaining($entries, $clientIp);
        if ($entry !== null) {
            throw ValidationException::withMessages(['url' => __('firewall.blocklists.you_listed', ['ip' => $clientIp, 'network' => $entry])]);
        }
    }

    private function applyRules(Request $request, Server $server, string $message): RedirectResponse
    {
        // The task reads all rules when it runs, so the last change wins.
        ServerTask::start($server, ScriptCall::firewallRules([]), $request->user());

        return redirect()->route('servers.firewall', $server)->with('status', $message.' '.__('firewall.toast.confirm'));
    }

    private function applyIgnores(Request $request, Server $server, string $message): RedirectResponse
    {
        ServerTask::start($server, ScriptCall::fail2banIgnore([]), $request->user());
        // The exceptions also apply to the blocklists (E-170).
        if ($server->blocklists()->exists()) {
            Blocklists::apply($server, $request->user());
        }

        return redirect()->route('servers.firewall', $server)->with('status', $message);
    }

    private function latest(Server $server, ServerScript $script): ?ServerTask
    {
        return $server->tasks()->where('script', $script->value)->latest('created_at')->latest('id')->first();
    }
}
