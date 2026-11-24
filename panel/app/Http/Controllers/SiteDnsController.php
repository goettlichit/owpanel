<?php

namespace App\Http\Controllers;

use App\Dns\SiteDns;
use App\Dns\SiteDnsWriter;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * DNS of a website, only for administrators (E-116): the A and AAAA
 * records the server needs next to the Hetzner zone, the responsible name
 * servers and the public resolver. Records in a Hetzner zone can be
 * created or corrected after a confirmation that shows old and new values.
 */
class SiteDnsController extends Controller
{
    public function show(Site $site, SiteDns $dns): View
    {
        Gate::authorize('manageDns', $site);
        $site->load('server');

        $check = $dns->check($site, fresh: (bool) session('dns.fresh'));

        return view('sites.dns', [
            'site' => $site,
            'dns' => $check,
            'changes' => collect(SiteDnsWriter::changes($check))->keyBy('key')->all(),
        ]);
    }

    /**
     * "Check DNS": forgets cached answers and asks again.
     */
    public function check(Site $site): RedirectResponse
    {
        Gate::authorize('manageDns', $site);

        return redirect()->route('sites.dns', $site)->with('dns.fresh', true)->with('status', __('dns.checked'));
    }

    /**
     * Confirmation before records are set: shows each record with its
     * current and its new value and lets the TTL be chosen.
     */
    public function confirm(Request $request, Site $site, SiteDns $dns): View|RedirectResponse
    {
        Gate::authorize('manageDns', $site);
        $site->load('server');
        $keys = array_map('strval', (array) $request->query('records', []));
        $changes = array_values(array_filter(
            SiteDnsWriter::changes($dns->check($site, fresh: true)),
            fn (array $change) => $keys === [] || in_array($change['key'], $keys, true),
        ));
        if ($changes === []) {
            return redirect()->route('sites.dns', $site)->with('status', __('dns.change.nothing'));
        }
        $ttls = array_filter(array_column($changes, 'ttl'));

        return view('sites.dns-change', [
            'site' => $site,
            'changes' => $changes,
            'ttl' => count(array_unique($ttls)) === 1 && in_array(reset($ttls), SiteDnsWriter::TTLS, true) ? reset($ttls) : SiteDnsWriter::DEFAULT_TTL,
        ]);
    }

    public function apply(Request $request, Site $site, SiteDnsWriter $writer): RedirectResponse
    {
        Gate::authorize('manageDns', $site);
        $validated = $request->validate([
            'records' => ['required', 'array', 'max:8'],
            'records.*' => ['string', 'max:300'],
            'ttl' => ['required', 'integer', Rule::in(SiteDnsWriter::TTLS)],
            'confirmed' => ['accepted'],
        ]);
        $site->load('server');

        $result = $writer->apply($site, array_values($validated['records']), (int) $validated['ttl'], $request->user());
        $redirect = redirect()->route('sites.dns', $site);
        if ($result['error'] !== null) {
            return $redirect->with('error', __('dns.change.failed', [
                'done' => $result['done'],
                'error' => __(trans()->has('dns.errors.'.$result['error']) ? 'dns.errors.'.$result['error'] : 'snapshots.errors.'.$result['error']),
            ]));
        }

        return $redirect->with('status', trim(trans_choice('dns.change.done', $result['done'], ['count' => $result['done']])
            .($result['pending'] ? ' '.__('dns.change.pending') : '')));
    }
}
