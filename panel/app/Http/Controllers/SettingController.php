<?php

namespace App\Http\Controllers;

use App\Backups\StorageBoxUsage;
use App\Gateway\Ingress;
use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Mail\TestMail;
use App\Models\Alert;
use App\Models\Server;
use App\Models\Setting;
use App\Notify\NotificationEvent;
use App\Oidc\OidcException;
use App\Oidc\OidcProvider;
use App\Oidc\OidcSettings;
use App\Oidc\PocketIdApi;
use App\Support\Audit;
use App\Support\Heartbeat;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Settings of the panel under System, each topic on its own page (E-118):
 * links to imprint and privacy policy shown in every footer (E-091), the
 * SMTP server for outgoing mail (E-094), the login with Pocket ID (E-114),
 * the external services (E-095, E-118, E-123) and the backup overview.
 */
class SettingController extends Controller
{
    public function edit(): View
    {
        Gate::authorize('manage', Setting::class);

        return view('system.settings', [
            'imprintUrl' => Setting::get(Setting::IMPRINT_URL),
            'privacyUrl' => Setting::get(Setting::PRIVACY_URL),
            'helpUrl' => Setting::get(Setting::HELP_URL),
            'ingressIpv4' => Setting::get(Setting::INGRESS_IPV4),
            'ingressIpv6' => Setting::get(Setting::INGRESS_IPV6),
            'panelServer' => Server::panelHost(),
        ]);
    }

    public function mail(): View
    {
        Gate::authorize('manage', Setting::class);

        return view('system.mail', [
            'mail' => MailSettings::values(),
            'mailConfigured' => MailSettings::configured(),
        ]);
    }

    public function oidc(): View
    {
        Gate::authorize('manage', Setting::class);

        return view('system.oidc', [
            'oidc' => [
                'configured' => OidcSettings::configured(),
                'name' => OidcSettings::customName(),
                'issuer' => OidcSettings::issuer(),
                'client_id' => OidcSettings::clientId(),
                'secret_set' => OidcSettings::clientSecret() !== null,
                'redirect_url' => OidcSettings::redirectUrl(),
            ],
        ]);
    }

    /**
     * External services (E-118, E-123): the Hetzner project token
     * and an optional own token for the DNS zones.
     */
    public function services(): View
    {
        Gate::authorize('manage', Setting::class);

        return view('system.services', [
            'hetznerConfigured' => HetznerCloud::configured(),
            'dnsTokenSet' => Setting::get(Setting::HETZNER_DNS_TOKEN) !== null,
            'pocketIdKeySet' => Setting::get(Setting::POCKET_ID_API_KEY) !== null,
            'pocketIdIssuer' => OidcSettings::issuer(),
            'heartbeatSet' => Heartbeat::url() !== null,
            'heartbeatState' => Heartbeat::url() !== null ? Heartbeat::state() : null,
        ]);
    }

    /**
     * System -> Backup: overview of snapshots and backups of all servers
     * and the emergency sheet.
     */
    public function backup(): View
    {
        Gate::authorize('manage', Setting::class);

        $servers = Server::orderBy('name')->get();
        $configured = HetznerCloud::configured();
        // Servers whose latest snapshot or backup failed: the open alerts
        // of the notifications (E-101), so the page and the messages agree.
        $failed = Alert::whereNull('resolved_at')
            ->whereIn('event', [NotificationEvent::SnapshotFailed->value, NotificationEvent::BackupFailed->value])
            ->pluck('key')->all();
        $snapshotFailed = fn (Server $server) => in_array('snapshot_failed:'.$server->getKey(), $failed, true);
        $backupFailed = fn (Server $server) => in_array('backup_failed:'.$server->getKey(), $failed, true);
        $snapshotsOn = $servers->filter(fn (Server $server) => $configured && $server->snapshot_enabled);
        $backupsSet = $servers->filter(fn (Server $server) => $server->backupConfigured());

        return view('system.backup', [
            'hetznerConfigured' => $configured,
            'servers' => $servers,
            'restored' => Setting::panelRestored(),
            'storageBoxes' => StorageBoxUsage::all(),
            'snapshotFailed' => $snapshotFailed,
            'backupFailed' => $backupFailed,
            // The line on top of each card shows its state (docs/DESIGN.md):
            // blue not set up, green in order, orange in progress, red failed.
            'accents' => [
                'snapshots' => match (true) {
                    $snapshotsOn->isEmpty() => 'blue',
                    $snapshotsOn->contains($snapshotFailed) => 'red',
                    default => 'green',
                },
                'backups' => match (true) {
                    $backupsSet->isEmpty() => 'blue',
                    $backupsSet->contains($backupFailed) => 'red',
                    $backupsSet->contains(fn (Server $server) => ! $server->backupActive()) => 'orange',
                    default => 'green',
                },
            ],
        ]);
    }

    /**
     * Hides the hint that the panel was restored from a backup.
     */
    public function dismissRestored(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);
        Setting::dismissPanelRestored();
        Audit::record('system.restored_dismissed', null, [], $request->user());

        return redirect()->route('settings.backup');
    }

    /**
     * Emergency sheet as a text file (E-099): Storage Box, and for each
     * server its subaccount and repository password, plus the steps to
     * restore. Everything needed when the panel itself is gone; meant for
     * a password manager or a safe place. Needs the password again and is
     * logged, like showing any password.
     */
    public function exportBackupKeys(Request $request): Response
    {
        Gate::authorize('manage', Setting::class);

        $user = $request->user();
        $domain = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $content = view('system.backup-keys', [
            'domain' => $domain,
            'user' => $user,
            'now' => now()->setTimezone((string) config('owpanel.timezone')),
            'servers' => Server::orderBy('name')->get(),
            'panelServer' => Server::panelHost(),
        ])->render();
        Audit::record('system.backup_keys_exported', null, [], $user);

        $filename = 'owpanel-'.__('settings.backup_keys.file').'-'.($domain !== '' ? $domain.'-' : '').now()->format('Y-m-d').'.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '-', $filename).'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'imprint_url' => ['nullable', 'url:https,http', 'max:500'],
            'privacy_url' => ['nullable', 'url:https,http', 'max:500'],
            'help_url' => ['nullable', 'url:https,http', 'max:500'],
        ]);
        Setting::put(Setting::IMPRINT_URL, $validated['imprint_url'] ?? null);
        Setting::put(Setting::PRIVACY_URL, $validated['privacy_url'] ?? null);
        Setting::put(Setting::HELP_URL, $validated['help_url'] ?? null);
        Audit::record('system.settings_changed', null, [], $request->user());

        return redirect()->route('settings.edit')->with('status', __('settings.saved'));
    }

    /**
     * The addresses the websites are reached at (E-162, E-193). Empty
     * fields use the public addresses of the panel server again. Existing
     * DNS records are not changed here; each website's DNS page shows and
     * sets the difference.
     */
    public function updateIngress(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'ingress_ipv4' => Ingress::rules(4),
            'ingress_ipv6' => Ingress::rules(6),
        ]);
        $before = Ingress::addresses();
        Ingress::save($validated['ingress_ipv4'] ?? null, $validated['ingress_ipv6'] ?? null);
        $after = Ingress::addresses();
        Audit::record('system.ingress_changed', null, ['before' => $before, 'after' => $after], $request->user());

        return redirect()->route('settings.edit')->with('status', __($before === $after ? 'settings.ingress.saved' : 'settings.ingress.changed'));
    }

    /**
     * SMTP server for outgoing mail (E-094). An empty server turns mail off.
     */
    public function updateMail(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'mail_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$|^\[?[0-9a-fA-F:.]+\]?$/'],
            'mail_port' => ['required_with:mail_host', 'nullable', 'integer', 'between:1,65535'],
            'mail_encryption' => ['required', 'in:'.implode(',', MailSettings::ENCRYPTIONS)],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_password_remove' => ['nullable', 'boolean'],
            'mail_from_address' => ['required_with:mail_host', 'nullable', 'email:rfc', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],
        ]);

        $host = trim((string) ($validated['mail_host'] ?? ''));
        if ($host === '') {
            MailSettings::save([
                'host' => null, 'port' => 587, 'encryption' => 'starttls', 'username' => null,
                'from_address' => null, 'from_name' => null,
            ], '');
        } else {
            $password = $request->boolean('mail_password_remove') ? '' : (($validated['mail_password'] ?? '') !== '' ? $validated['mail_password'] : null);
            MailSettings::save([
                'host' => $host,
                'port' => (int) $validated['mail_port'],
                'encryption' => $validated['mail_encryption'],
                'username' => trim((string) ($validated['mail_username'] ?? '')) ?: null,
                'from_address' => $validated['mail_from_address'],
                'from_name' => trim((string) ($validated['mail_from_name'] ?? '')) ?: null,
            ], $password);
        }
        MailSettings::apply(fresh: true);
        Audit::record('system.mail_settings_changed', null, [], $request->user());

        return redirect()->route('settings.mail')->with('status', __($host === '' ? 'settings.mail.disabled' : 'settings.mail.saved'));
    }

    /**
     * Sends a test mail to the own address, right away, so a wrong server
     * or password shows up at once.
     */
    public function testMail(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        if (! MailSettings::configured()) {
            return redirect()->route('settings.mail')->with('error', __('settings.mail.not_configured'));
        }

        // Five test mails a minute; a toast instead of the bare 429 page.
        $user = $request->user();
        $key = 'test-mail|'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect()->route('settings.mail')->with('error', __('settings.mail.test_throttled', ['seconds' => RateLimiter::availableIn($key)]));
        }
        RateLimiter::hit($key, 60);

        try {
            Mail::to($user->email)->locale($user->locale ?? app()->getLocale())->send(new TestMail);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('settings.mail')->with('error', __('settings.mail.test_failed', [
                'error' => Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 300),
            ]));
        }
        Audit::record('system.test_mail_sent', null, [], $user);

        return redirect()->route('settings.mail')->with('status', __('settings.mail.test_sent', ['email' => $user->email]));
    }

    /**
     * Token of a Hetzner Cloud project for snapshots (E-095). Checked with
     * a read call before it is stored, encrypted.
     */
    public function updateHetzner(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        if ($request->boolean('hetzner_token_remove')) {
            Setting::put(Setting::HETZNER_TOKEN, null);
            Audit::record('system.hetzner_token_removed', null, [], $request->user());

            return redirect()->route('settings.services')->with('status', __('settings.hetzner.removed'));
        }

        $token = (string) $request->validate([
            'hetzner_token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{20,128}$/'],
        ])['hetzner_token'];

        try {
            (new HetznerCloud($token))->verify();
        } catch (HetznerException $e) {
            return back()->withErrors(['hetzner_token' => __('snapshots.errors.'.$e->errorCode)]);
        }
        Setting::put(Setting::HETZNER_TOKEN, Crypt::encryptString($token));
        Audit::record('system.hetzner_token_changed', null, [], $request->user());

        return redirect()->route('settings.services')->with('status', __('settings.hetzner.saved'));
    }

    /**
     * Optional API key of Pocket ID (E-138), checked with a read call before
     * it is stored, encrypted.
     */
    /**
     * Address of the heartbeat to an outside monitoring (E-160). Called
     * once before saving, so a typo shows at once.
     */
    public function updateHeartbeat(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        if ($request->boolean('heartbeat_url_remove')) {
            Heartbeat::save(null);
            Audit::record('system.heartbeat_removed', null, [], $request->user());

            return redirect()->route('settings.services')->with('status', __('settings.heartbeat.removed'));
        }

        $url = trim((string) $request->validate([
            'heartbeat_url' => ['required', 'string', 'max:500'],
        ])['heartbeat_url']);

        $error = Heartbeat::ping($url);
        if ($error !== null) {
            Setting::put(Setting::HEARTBEAT_STATE, null);

            return back()->withErrors(['heartbeat_url' => __('settings.heartbeat.failed', ['error' => $error])]);
        }
        $state = Setting::get(Setting::HEARTBEAT_STATE);
        Heartbeat::save($url);
        Setting::put(Setting::HEARTBEAT_STATE, $state);
        Audit::record('system.heartbeat_changed', null, [], $request->user());

        return redirect()->route('settings.services')->with('status', __('settings.heartbeat.saved'));
    }

    public function updatePocketIdApi(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        if ($request->boolean('pocket_id_api_key_remove')) {
            Setting::put(Setting::POCKET_ID_API_KEY, null);
            Audit::record('system.pocket_id_api_key_removed', null, [], $request->user());

            return redirect()->route('settings.services')->with('status', __('settings.pocket_id_api.removed'));
        }
        if (OidcSettings::issuer() === null) {
            return back()->withErrors(['pocket_id_api_key' => __('settings.pocket_id_api.no_issuer')]);
        }

        $key = (string) $request->validate([
            'pocket_id_api_key' => ['required', 'string', 'regex:/^[\x21-\x7E]{16,256}$/'],
        ])['pocket_id_api_key'];

        try {
            (new PocketIdApi((string) OidcSettings::issuer(), $key))->verify();
        } catch (RuntimeException $e) {
            return back()->withErrors(['pocket_id_api_key' => $e->getMessage()]);
        }
        Setting::put(Setting::POCKET_ID_API_KEY, Crypt::encryptString($key));
        Audit::record('system.pocket_id_api_key_changed', null, [], $request->user());

        return redirect()->route('settings.services')->with('status', __('settings.pocket_id_api.saved'));
    }

    /**
     * Optional token of the Hetzner project that holds the DNS zones
     * (E-118). Checked by reading zones; without it, DNS uses the project
     * token.
     */
    public function updateHetznerDns(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        if ($request->boolean('hetzner_dns_token_remove')) {
            Setting::put(Setting::HETZNER_DNS_TOKEN, null);
            Audit::record('system.hetzner_dns_token_removed', null, [], $request->user());

            return redirect()->route('settings.services')->with('status', __('settings.services.dns_removed'));
        }

        $token = (string) $request->validate([
            'hetzner_dns_token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{20,128}$/'],
        ])['hetzner_dns_token'];

        try {
            (new HetznerCloud($token))->verifyZones();
        } catch (HetznerException $e) {
            return back()->withErrors(['hetzner_dns_token' => __('snapshots.errors.'.$e->errorCode)]);
        }
        Setting::put(Setting::HETZNER_DNS_TOKEN, Crypt::encryptString($token));
        Audit::record('system.hetzner_dns_token_changed', null, [], $request->user());

        return redirect()->route('settings.services')->with('status', __('settings.services.dns_saved'));
    }

    /**
     * Login with Pocket ID or another OpenID Connect provider (E-114). The
     * connection is checked before anything is stored; an empty address
     * turns the login off.
     */
    public function updateOidc(Request $request): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        $validated = $request->validate([
            'oidc_name' => ['nullable', 'string', 'max:50', 'regex:/^[^\x00-\x1F\x7F]*$/u'],
            'oidc_issuer' => ['nullable', 'string', 'max:255'],
            'oidc_client_id' => ['required_with:oidc_issuer', 'nullable', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'oidc_client_secret' => ['nullable', 'string', 'max:512', 'regex:/^[\x21-\x7E]+$/'],
        ]);
        $name = trim((string) ($validated['oidc_name'] ?? '')) ?: null;
        $address = trim((string) ($validated['oidc_issuer'] ?? ''));

        if ($address === '') {
            $wasConfigured = OidcSettings::issuer() !== null;
            OidcSettings::disable($name);
            Audit::record($wasConfigured ? 'system.oidc_disabled' : 'system.oidc_settings_changed', null, [], $request->user());

            return redirect()->route('settings.oidc')->with('status', __($wasConfigured ? 'settings.oidc.disabled' : 'settings.saved'));
        }

        $clientId = (string) $validated['oidc_client_id'];
        $secret = ($validated['oidc_client_secret'] ?? '') !== '' ? (string) $validated['oidc_client_secret'] : null;
        if ($secret === null && OidcSettings::clientSecret() === null) {
            return back()->withInput($request->except('oidc_client_secret'))
                ->withErrors(['oidc_client_secret' => __('settings.oidc.secret_required')]);
        }

        try {
            $issuer = OidcProvider::issuerFor($address);
            (new OidcProvider($issuer, $clientId, $secret ?? OidcSettings::clientSecret()))->check();
        } catch (OidcException $e) {
            Log::info('OIDC settings rejected: '.$e->getMessage());

            return back()->withInput($request->except('oidc_client_secret'))
                ->withErrors(['oidc_issuer' => __('settings.oidc.errors.'.$e->errorCode)]);
        }

        OidcSettings::save($issuer, $clientId, $secret, $name);
        Audit::record('system.oidc_settings_changed', null, [], $request->user());

        return redirect()->route('settings.oidc')->with('status', __('settings.oidc.saved', ['name' => OidcSettings::name()]));
    }

    /**
     * Reads discovery document and keys of the provider afresh.
     */
    public function testOidc(): RedirectResponse
    {
        Gate::authorize('manage', Setting::class);

        try {
            $result = OidcProvider::fromSettings()->check();
        } catch (OidcException $e) {
            Log::info('OIDC check failed: '.$e->getMessage());

            return redirect()->route('settings.oidc')->with('error', __('settings.oidc.test_failed', [
                'error' => __('settings.oidc.errors.'.$e->errorCode),
            ]));
        }

        return redirect()->route('settings.oidc')->with('status', __('settings.oidc.test_ok', [
            'issuer' => OidcSettings::issuer(),
            'algorithms' => implode(', ', $result['algorithms']),
            'keys' => $result['keys'],
        ]));
    }
}
