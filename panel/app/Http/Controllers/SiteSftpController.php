<?php

namespace App\Http\Controllers;

use App\Enums\ServerScript;
use App\Enums\SiteStatus;
use App\Gateway\Ingress;
use App\Jobs\ReadSsh;
use App\Models\ServerTask;
use App\Models\SftpKey;
use App\Models\Site;
use App\Servers\ScriptCall;
use App\Support\Audit;
use App\Support\DnsResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * SFTP access of a website (E-089). The website's Linux user logs in with a
 * password, a key or both and only sees its own directory. Every change
 * starts site-sftp, which writes the whole state to the server.
 */
class SiteSftpController extends Controller
{
    /**
     * Route names of the page: "sites." in the administration, "my.sites."
     * in the customer area (E-143).
     */
    protected string $area = 'sites.';

    public function index(Site $site): View
    {
        Gate::authorize('manageSftp', $site);

        return $this->page($site, revealed: false);
    }

    /**
     * Shows the password in plain text, recorded in the audit log like
     * database passwords (E-078, E-135).
     */
    public function reveal(Request $request, Site $site): View|RedirectResponse
    {
        Gate::authorize('manageSftp', $site);

        if ($site->sftp_password === null) {
            return redirect()->route($this->area.'sftp', $site);
        }
        Audit::record('site.sftp_password_revealed', $site, ['domain' => $site->domain], $request->user());

        return $this->page($site, revealed: true);
    }

    /**
     * Turns access on or off. Turned on without password and key, the
     * panel creates a password, so the access works right away.
     */
    public function toggle(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manageSftp', $site);

        $enabled = ! $site->sftp_enabled;
        $site->forceFill(['sftp_enabled' => $enabled]);
        if ($enabled && $site->sftp_password === null && ! $site->sftpKeys()->exists()) {
            $site->forceFill(['sftp_password' => SftpKey::generatePassword()]);
        }
        $site->save();
        Audit::record($enabled ? 'site.sftp_enabled' : 'site.sftp_disabled', $site, ['domain' => $site->domain], $request->user());

        return $this->sync($request, $site, __($enabled ? 'sites.sftp.toast.enabled' : 'sites.sftp.toast.disabled'), always: true);
    }

    /**
     * Creates a new random password or removes it, so only keys work.
     */
    public function password(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manageSftp', $site);
        $action = $request->validate(['action' => ['required', 'in:generate,remove']])['action'];

        if ($action === 'generate') {
            $site->forceFill(['sftp_password' => SftpKey::generatePassword()])->save();
            Audit::record('site.sftp_password_changed', $site, ['domain' => $site->domain], $request->user());

            return $this->sync($request, $site, __('sites.sftp.toast.password'));
        }

        $site->forceFill(['sftp_password' => null])->save();
        Audit::record('site.sftp_password_removed', $site, ['domain' => $site->domain], $request->user());

        return $this->sync($request, $site, __('sites.sftp.toast.password_removed'));
    }

    public function storeKey(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manageSftp', $site);

        $validated = $request->validate([
            'key_name' => ['nullable', 'string', 'max:100'],
            'public_key' => ['required', 'string', 'max:'.SftpKey::MAX_LENGTH],
        ]);
        $parsed = SftpKey::parse($validated['public_key']);
        if ($parsed === null) {
            throw ValidationException::withMessages(['public_key' => __('sites.sftp.validation.key')]);
        }
        if ($site->sftpKeys()->where('fingerprint', $parsed['fingerprint'])->exists()) {
            throw ValidationException::withMessages(['public_key' => __('sites.sftp.validation.duplicate')]);
        }
        if ($site->sftpKeys()->count() >= SftpKey::MAX_PER_SITE) {
            return back()->with('error', __('sites.sftp.too_many', ['count' => SftpKey::MAX_PER_SITE]));
        }

        $name = trim((string) ($validated['key_name'] ?? ''));
        $key = $site->sftpKeys()->create([
            'name' => $name !== '' ? $name : ($parsed['comment'] !== '' ? $parsed['comment'] : $parsed['type']),
            'public_key' => $parsed['key'],
            'fingerprint' => $parsed['fingerprint'],
        ]);
        Audit::record('site.sftp_key_added', $site, ['domain' => $site->domain, 'fingerprint' => $key->fingerprint], $request->user());

        return $this->sync($request, $site, __('sites.sftp.toast.key_added'));
    }

    public function destroyKey(Request $request, Site $site, SftpKey $sftpKey): RedirectResponse
    {
        Gate::authorize('manageSftp', $site);
        abort_unless($sftpKey->site_id === $site->id, 404);

        $sftpKey->delete();
        Audit::record('site.sftp_key_deleted', $site, ['domain' => $site->domain, 'fingerprint' => $sftpKey->fingerprint], $request->user());

        return $this->sync($request, $site, __('sites.sftp.toast.key_deleted'));
    }

    private function page(Site $site, bool $revealed): View
    {
        $site->load('server');

        return view('sites.sftp', [
            'site' => $site,
            // Whether the domain already reaches this server; while a website
            // is moved here it does not yet, so the server address comes first.
            // Behind the gateway it never does: the domain points to the
            // ingress addresses, SFTP goes to the server's host name (E-166).
            'domainReaches' => $reaches = Ingress::reaches($site->server),
            'domainHere' => $site->server->public_ipv4 === null
                || app(DnsResolver::class)->pointsTo($site->domain, [$site->server->public_ipv4, $site->server->public_ipv6]),
            'keys' => $site->sftpKeys()->orderBy('created_at')->orderBy('id')->get(),
            'sync' => $site->tasks()->where('script', ServerScript::SiteSftp->value)->latest('created_at')->latest('id')->first(),
            'revealed' => $revealed,
            'area' => $this->area,
            // AllowUsers/AllowGroups without owpanel-sftp keep customers out
            // (E-155); only administrators can change that, so only they ask.
            'sshBlocked' => auth()->user()?->isAdmin() === true && $site->sftp_enabled
                && self::sftpAllowed((string) $site->server_id) === false,
        ]);
    }

    /**
     * Starts site-sftp for an active website. While access is off, keys and
     * password only change in the panel; the server learns them when access
     * is turned on. The task reads the state when it runs.
     */
    private function sync(Request $request, Site $site, string $message, bool $always = false): RedirectResponse
    {
        if ($site->status !== SiteStatus::Active) {
            $message .= ' '.__('sites.sftp.toast.later');
        } elseif ($always || $site->sftp_enabled) {
            ServerTask::start($site->server, ScriptCall::siteSftp($site->system_user, false), $request->user(), $site);
        }

        return redirect()->route($this->area.'sftp', $site)->with('status', $message);
    }

    /**
     * Whether sshd lets the group owpanel-sftp in (E-155), kept for ten
     * minutes; changing the SSH settings in the panel forgets it.
     */
    public static function sftpAllowed(string $serverId): bool
    {
        return (bool) Cache::remember('ssh-sftp-allowed:'.$serverId, now()->addMinutes(10),
            fn () => ReadSsh::await($serverId)['sftp_allowed'] ?? true);
    }
}
