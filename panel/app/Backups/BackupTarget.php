<?php

namespace App\Backups;

use App\Hetzner\HetznerCloud;
use App\Hetzner\HetznerException;
use App\Models\Server;
use App\Models\ServerTask;
use App\Models\User;
use App\Servers\ScriptCall;
use Illuminate\Support\Str;

/**
 * Connecting a server to its backup target, a Hetzner Storage Box chosen
 * per server (E-096, E-097, E-100). Each server gets a subaccount of its
 * own whose home is "<path>/<server ID>", so a server only ever reaches its
 * own backups. Its restic repository lies in "restic" below that, with its
 * own random password stored encrypted in the panel.
 */
final class BackupTarget
{
    /**
     * Repository directory inside the home of the server's subaccount.
     */
    public const REPOSITORY = 'restic';

    /**
     * Home directory of the server's subaccount on the Storage Box.
     */
    public static function homeDirectory(Server $server): string
    {
        return $server->backup_path.'/'.strtolower((string) $server->getKey());
    }

    /**
     * Starts backup-setup on a server. A server without a repository
     * password gets one; it never changes afterwards, because the
     * repository can only be opened with it. The subaccount is created or
     * prepared when the task runs, see call().
     */
    public static function setUp(Server $server, ?User $user): ?ServerTask
    {
        if (! $server->backupConfigured() || $server->backup_host === null) {
            return null;
        }
        if ($server->backup_repo_password === null) {
            $server->forceFill(['backup_repo_password' => Str::password(48, symbols: false)])->save();
        }
        // Stored with the login known so far; the task rebuilds its call
        // when it runs and never keeps a password.
        $call = ScriptCall::backupSetup(
            (string) $server->backup_host,
            (string) ($server->backup_account ?? 'u000'),
            self::REPOSITORY,
            (string) $server->backup_repo_password,
        );

        return ServerTask::start($server, $call, $user);
    }

    /**
     * The call of backup-setup as it runs. Makes sure the server has its
     * subaccount on its Storage Box, gives it a new random password that
     * only this call knows, and hands that to the server once to install
     * its key. Throws a HetznerException when the API fails.
     */
    public static function call(Server $server, ?HetznerCloud $api = null): ScriptCall
    {
        $api ??= HetznerCloud::fromSettings();
        $boxId = (int) $server->backup_box_id;
        if ($boxId === 0) {
            throw new HetznerException('not_found', 'No Storage Box chosen for this server.');
        }
        $password = HetznerCloud::storagePassword();

        $sub = $server->backup_subaccount_id !== null ? $api->subaccount($boxId, $server->backup_subaccount_id) : null;
        if ($sub !== null && $sub['home_directory'] !== '' && trim($sub['home_directory'], '/') !== self::homeDirectory($server)) {
            $sub = null; // the path changed: a new subaccount for the new place
        }

        if ($sub === null) {
            $id = $api->createSubaccount($boxId, self::homeDirectory($server), $password,
                'OWPanel '.$server->display_name,
                [HetznerCloud::LABEL => 'backup', HetznerCloud::LABEL_SERVER => strtolower((string) $server->getKey())],
                (bool) $server->backup_external);
            $sub = $api->subaccount($boxId, $id) ?? throw new HetznerException('not_found');
        } else {
            $api->setSubaccountExternal($boxId, $sub['id'], (bool) $server->backup_external);
            $api->resetSubaccountPassword($boxId, $sub['id'], $password);
        }

        // The subaccount logs in at the host of the box: its own name
        // ("u123456-sub1.your-storagebox.de") takes a while to appear in DNS.
        $host = $server->backup_host ?? $sub['server'];
        $server->forceFill([
            'backup_subaccount_id' => $sub['id'],
            'backup_host' => $host,
            'backup_account' => $sub['username'],
        ])->save();

        return ScriptCall::backupSetup($host, $sub['username'], self::REPOSITORY, (string) $server->backup_repo_password, $password);
    }

    /**
     * The box itself is reachable from outside only while a server using
     * it needs that (E-097). Only this setting of the box is changed.
     */
    public static function applyBoxAccess(int $boxId, ?HetznerCloud $api = null): void
    {
        $external = Server::where('backup_box_id', $boxId)->where('backup_external', true)->exists();
        ($api ?? HetznerCloud::fromSettings())->setBoxExternal($boxId, $external);
    }
}
