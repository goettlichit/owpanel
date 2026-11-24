<?php

namespace App\Http\Controllers;

use App\Dns\ServerDns;
use App\Dns\SiteDnsWriter;
use App\Hetzner\HetznerException;
use App\Models\Server;
use App\Support\Audit;
use App\Support\DnsResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * DNS of a server, for administrators (E-153): A and AAAA of its host name,
 * set in a Hetzner zone like for websites, and the reverse DNS of its
 * addresses, set through the Hetzner Cloud API.
 */
class ServerDnsController extends Controller
{
    public function show(Server $server, ServerDns $dns): View
    {
        Gate::authorize('manageDns', $server);
        $check = $dns->check($server, fresh: (bool) session('dns.fresh'));

        return view('servers.dns', [
            'server' => $server,
            'dns' => $check,
            'changes' => SiteDnsWriter::changes($check['records']),
            'ttls' => SiteDnsWriter::TTLS,
        ]);
    }

    public function check(Server $server): RedirectResponse
    {
        Gate::authorize('manageDns', $server);

        return redirect()->route('servers.dns', $server)->with('dns.fresh', true)->with('status', __('dns.checked'));
    }

    /**
     * Sets A and AAAA of the host name in its Hetzner zone. The page shows
     * old and new values next to the button, so it is the confirmation.
     */
    public function records(Request $request, Server $server, ServerDns $dns): RedirectResponse
    {
        Gate::authorize('manageDns', $server);
        $validated = $request->validate([
            'records' => ['required', 'array', 'max:2'],
            'records.*' => ['string', 'max:300'],
            'ttl' => ['required', 'integer', Rule::in(SiteDnsWriter::TTLS)],
        ]);

        $result = SiteDnsWriter::setRecords($dns->check($server, fresh: true)['records'] ?? [], array_values($validated['records']), (int) $validated['ttl'],
            function (array $change) use ($request, $server, $validated): void {
                Audit::record('server.dns_record_set', $server, [
                    'host' => $change['host'], 'type' => $change['type'],
                    'old' => implode(', ', $change['current']), 'new' => $change['expected'], 'ttl' => (int) $validated['ttl'],
                ], $request->user());
            });

        $redirect = redirect()->route('servers.dns', $server)->with('dns.fresh', true);
        if ($result['error'] !== null) {
            return $redirect->with('error', __('dns.change.failed', ['done' => $result['done'], 'error' => $this->error($result['error'])]));
        }

        return $redirect->with('status', trim(trans_choice('dns.change.done', $result['done'], ['count' => $result['done']])
            .($result['pending'] ? ' '.__('dns.change.pending') : '')));
    }

    /**
     * Sets or resets the reverse DNS of one of the server's addresses.
     */
    public function ptr(Request $request, Server $server, ServerDns $dns): RedirectResponse
    {
        Gate::authorize('manageDns', $server);
        $addresses = array_values(array_filter([$server->public_ipv4, $server->public_ipv6]));
        $addresses = array_map(fn (string $ip) => (string) inet_ntop((string) inet_pton($ip)), $addresses);
        $validated = $request->validate([
            'ip' => ['required', 'string', Rule::in($addresses)],
            'dns_ptr' => ['nullable', 'string', 'max:253', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! ServerDns::validName(strtolower(rtrim((string) $value, '.')))) {
                    $fail(__('dns.server.ptr_invalid'));
                }
            }],
        ]);
        $name = ($validated['dns_ptr'] ?? '') === '' ? null : strtolower(rtrim($validated['dns_ptr'], '.'));
        $current = collect($dns->check($server)['ptr'])->firstWhere('ip', $validated['ip'])['current'] ?? null;

        try {
            $dns->setPtr($server, $validated['ip'], $name);
        } catch (HetznerException $e) {
            return back()->withInput()->with('error', __('dns.server.ptr_failed', ['error' => $this->error($e->errorCode)]));
        }
        Audit::record('server.dns_ptr_set', $server, ['ip' => $validated['ip'], 'old' => $current ?? '', 'new' => $name ?? ''], $request->user());
        if ($name !== null) {
            app(DnsResolver::class)->forget($name);
        }

        return redirect()->route('servers.dns', $server)
            ->with('status', $name === null ? __('dns.server.ptr_reset', ['ip' => $validated['ip']]) : __('dns.server.ptr_done', ['ip' => $validated['ip'], 'name' => $name]));
    }

    private function error(string $code): string
    {
        return __(trans()->has('dns.errors.'.$code) ? 'dns.errors.'.$code : 'snapshots.errors.'.$code);
    }
}
