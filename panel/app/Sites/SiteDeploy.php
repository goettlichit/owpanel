<?php

namespace App\Sites;

use App\Enums\ServerScript;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\Site;
use App\Models\User;
use App\Servers\ScriptCall;

/**
 * The tasks that set up, change, suspend and remove a website (E-195).
 * Every website has two parts: the backend on its own server (site-create)
 * and the gateway on the panel server (gateway-site), which takes the
 * visitors. On a single server both run there, the same way.
 */
final class SiteDeploy
{
    /**
     * Backend first, then the gateway once the backend is there. Returns
     * the gateway task, which ends the chain and decides the website's state.
     *
     * @param  array{memory: int, upload: int, time: int, processes: int, errors: bool}|null  $settings
     */
    public static function configure(
        Site $site,
        ?User $user,
        bool $tls,
        ?bool $www = null,
        ?string $domain = null,
        ?string $previousDomain = null,
        bool $existingOnly = false,
        ?string $php = null,
        ?array $settings = null,
        ?ServerTask $after = null,
        ?string $batch = null,
    ): ServerTask {
        $domain ??= $site->domain;
        $backend = ServerTask::start(
            $site->server,
            self::backendCall($site, $domain, (bool) $site->tls, $existingOnly, $php, $settings, $previousDomain),
            $user, $site, after: $after, batch: $batch,
        );

        return self::gateway($site, $user, $tls, $www ?? (bool) $site->www, $domain, $previousDomain, $site->suspended_at !== null, $settings, $backend, $batch);
    }

    /**
     * Suspends a website (E-127): the gateway shows the suspension page
     * first, then the server rests PHP, cron and SFTP. Unsuspending goes
     * the other way and writes the backend again, which a website suspended
     * before the gateway existed does not have yet.
     */
    public static function suspend(Site $site, ?User $user, bool $suspend = true, ?ServerTask $after = null): ServerTask
    {
        if ($suspend) {
            $gateway = self::gateway($site, $user, false, (bool) $site->www, $site->domain, null, true, null, $after, null);

            return ServerTask::start($site->server, ScriptCall::siteSuspend($site->system_user), $user, $site, after: $gateway);
        }

        $unsuspend = ServerTask::start($site->server, ScriptCall::siteSuspend($site->system_user, false), $user, $site, after: $after);
        $backend = ServerTask::start($site->server, self::backendCall($site, $site->domain, (bool) $site->tls, true, null, null), $user, $site, after: $unsuspend);

        return self::gateway($site, $user, false, (bool) $site->www, $site->domain, null, false, null, $backend, null);
    }

    /**
     * Removes a website: the gateway lets go first, then site-delete
     * removes everything on the website's server and with it the site.
     */
    public static function remove(Site $site, ?User $user): ServerTask
    {
        $gateway = ServerTask::start(self::gatewayServer($site), ScriptCall::gatewayRemove($site->system_user), $user, $site);

        return ServerTask::start($site->server, ScriptCall::siteDelete($site->system_user), $user, $site, after: $gateway);
    }

    /**
     * Only the gateway of a website, e.g. after the installer for a
     * suspended website, whose backend rests.
     */
    public static function gatewayOnly(Site $site, ?User $user, ?string $batch = null): ServerTask
    {
        return self::gateway($site, $user, false, (bool) $site->www, $site->domain, null, $site->suspended_at !== null, null, null, $batch);
    }

    /**
     * Writes the backend again once the gateway is done, if it changed the
     * scheme, so session cookies follow HTTPS (E-176), or after a domain
     * change, so the backend lets go of the previous domain (E-112). Null
     * if nothing changed.
     */
    public static function followGateway(Site $site, ServerTask $gateway): ?ServerTask
    {
        $backend = $gateway->after;
        if ($backend === null || $backend->script !== ServerScript::SiteCreate || ! is_array($backend->arguments)) {
            return null;
        }
        $tls = ($gateway->result['tls'] ?? false) === true;
        if ($tls === in_array('--https', $backend->arguments, true) && ! in_array('--alias', $backend->arguments, true)) {
            return null;
        }

        return ServerTask::start($site->server, self::backendCall($site, $site->domain, $tls, true, null, null), null, $site);
    }

    /**
     * Whether gateway and backend of the website are on the same server,
     * as on a single server (E-195).
     */
    public static function local(Site $site): bool
    {
        $gateway = Server::panelHost();

        return $gateway === null || $gateway->is($site->server);
    }

    /**
     * The server whose gateway takes the visitors: the panel server.
     */
    public static function gatewayServer(Site $site): Server
    {
        return Server::panelHost() ?? $site->server;
    }

    /**
     * @param  array{memory: int, upload: int, time: int, processes: int, errors: bool}|null  $settings
     */
    private static function backendCall(Site $site, string $domain, bool $https, bool $existingOnly, ?string $php, ?array $settings, ?string $alias = null): ScriptCall
    {
        $server = $site->server;

        return ScriptCall::siteCreate(
            $domain,
            $site->system_user,
            $https,
            listen: self::local($site) ? '127.0.0.1' : $server->backendAddress(),
            // The gateway's private address comes with "Server hinzufügen"
            // (M5-5); until then only the panel server's own backend.
            gateway: '127.0.0.1',
            existingOnly: $existingOnly,
            php: $php,
            settings: $settings,
            alias: $alias,
        );
    }

    /**
     * @param  array{memory: int, upload: int, time: int, processes: int, errors: bool}|null  $settings
     */
    private static function gateway(Site $site, ?User $user, bool $tls, bool $www, string $domain, ?string $previousDomain, bool $suspended, ?array $settings, ?ServerTask $after, ?string $batch): ServerTask
    {
        $server = $site->server;
        $values = $settings ?? PhpSettings::effective($site);
        $local = self::local($site);

        return ServerTask::start(
            self::gatewayServer($site),
            ScriptCall::gatewaySite(
                $domain,
                $site->system_user,
                $server->backendId(),
                $local ? '127.0.0.1' : $server->backendAddress(),
                // The panel server takes its own certificate.
                $local ? null : (string) $server->backend_cert,
                // Requests a certificate; one that covers the site stays anyway.
                $tls,
                $www,
                $previousDomain,
                $suspended,
                // Upload and run time of PHP plus a margin, as the backend (E-173).
                $values['upload'] + 8,
                $values['time'] + 30,
            ),
            $user, $site, after: $after, batch: $batch,
        );
    }
}
