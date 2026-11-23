<?php

namespace App\Servers;

use App\Enums\ServerScript;
use App\Models\CronJob;
use App\Models\Database;
use App\Models\SftpKey;
use App\Models\Site;
use App\Sites\Domain;
use App\Sites\WebserverRules;
use App\Support\IpNetwork;
use InvalidArgumentException;

/**
 * One call of an approved server script with its parameters.
 *
 * Calls are built through the named constructors, which validate the input
 * for each script (docs/SICHERHEIT.md, "Eingaben"). The scripts validate it
 * once more. The command line escapes every parameter on its own; the panel
 * never passes free-form shell commands.
 */
final readonly class ScriptCall
{
    /** Snapshot name for the newest backup of a website (E-106). */
    public const LATEST = 'latest';

    /**
     * @param  list<string>  $arguments
     * @param  string|null  $stdin  Secret input such as a database password. It
     *                              goes to the script's standard input and is
     *                              never stored with the task (E-078).
     * @param  string|null  $stdinFile  File sent to standard input after $stdin,
     *                                  e.g. a dump for db-import (E-082).
     * @param  string|null  $stdoutFile  File that receives standard output, e.g.
     *                                   the dump of db-export. The log and the
     *                                   result line then come from stderr.
     */
    public function __construct(
        public ServerScript $script,
        public array $arguments = [],
        #[\SensitiveParameter] public ?string $stdin = null,
        public ?string $stdinFile = null,
        public ?string $stdoutFile = null,
    ) {
        foreach ($arguments as $argument) {
            if (! is_string($argument) || $argument === '' || strlen($argument) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $argument)) {
                throw new InvalidArgumentException('Invalid script argument.');
            }
        }

        if (! array_is_list($arguments)) {
            throw new InvalidArgumentException('Script arguments must be a list.');
        }
    }

    /**
     * Harmless test script (server/bin/panel-test).
     */
    public static function panelTest(int $steps = 3, bool $fail = false): self
    {
        if ($steps < 1 || $steps > 10) {
            throw new InvalidArgumentException('Steps must be between 1 and 10.');
        }

        return new self(ServerScript::PanelTest, [
            '--steps', (string) $steps,
            ...($fail ? ['--fail'] : []),
        ]);
    }

    /**
     * Reads state and load of the server (server/bin/server-status, E-075).
     */
    public static function serverStatus(): self
    {
        return new self(ServerScript::ServerStatus, []);
    }

    /**
     * Creates a database with its user, or sets a new password
     * (server/bin/db-create, E-078). The password goes on stdin.
     */
    public static function dbCreate(string $name, #[\SensitiveParameter] ?string $password = null): self
    {
        self::assertDatabaseName($name);

        return new self(ServerScript::DbCreate, ['--name', $name], $password === null ? null : $password."\n");
    }

    /**
     * Deletes a database and its user (server/bin/db-delete, E-078).
     */
    public static function dbDelete(string $name): self
    {
        self::assertDatabaseName($name);

        return new self(ServerScript::DbDelete, ['--name', $name]);
    }

    /**
     * Dump of a database into $file, compressed (server/bin/db-export, E-082).
     */
    public static function dbExport(string $name, #[\SensitiveParameter] string $password, string $file): self
    {
        self::assertDatabaseName($name);

        return new self(ServerScript::DbExport, ['--name', $name], $password."\n", stdoutFile: $file);
    }

    /**
     * Imports the dump in $file (server/bin/db-import, E-082).
     */
    public static function dbImport(string $name, #[\SensitiveParameter] string $password, string $file, bool $gzip): self
    {
        self::assertDatabaseName($name);

        return new self(ServerScript::DbImport, $gzip ? ['--name', $name, '--gzip'] : ['--name', $name], $password."\n", stdinFile: $file);
    }

    /**
     * Runs SQL as the database user (server/bin/db-query, E-079). Password
     * and SQL go on stdin; nothing of them reaches the command line.
     */
    public static function dbQuery(string $name, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $sql, int $maxRows = 1000): self
    {
        self::assertDatabaseName($name);
        if ($maxRows < 1 || $maxRows > 1000) {
            throw new InvalidArgumentException('Rows must be between 1 and 1000.');
        }
        if (str_contains($password, "\n") || trim($sql) === '') {
            throw new InvalidArgumentException('Invalid password or empty SQL.');
        }

        return new self(ServerScript::DbQuery, ['--name', $name, '--max-rows', (string) $maxRows], $password."\n".$sql);
    }

    private static function assertDatabaseName(string $name): void
    {
        if (! Database::isValidName($name)) {
            throw new InvalidArgumentException('Invalid database name.');
        }
    }

    /**
     * State of SSH password login and the SSH keys of login accounts
     * (server/bin/server-ssh, E-155).
     */
    public static function sshStatus(): self
    {
        return new self(ServerScript::ServerSsh, ['--status']);
    }

    /**
     * Allows or forbids password login over SSH for administrators; SFTP
     * customers keep theirs (E-155).
     */
    public static function sshPasswordLogin(bool $allowed): self
    {
        return new self(ServerScript::ServerSsh, ['--password-login', $allowed ? 'on' : 'off']);
    }

    /**
     * Refreshes the package lists or installs all pending updates
     * (server/bin/server-upgrade, E-154).
     */
    public static function serverUpgrade(bool $install): self
    {
        return new self(ServerScript::ServerUpgrade, [$install ? '--install' : '--refresh']);
    }

    /**
     * Pending and installed updates, reboot state (server/bin/server-updates, E-076).
     */
    public static function serverUpdates(): self
    {
        return new self(ServerScript::ServerUpdates, []);
    }

    /**
     * Expiry of the certificates on the server (server/bin/site-certificates, E-076).
     */
    public static function siteCertificates(): self
    {
        return new self(ServerScript::SiteCertificates, []);
    }

    /**
     * Restarts the server 15 seconds later (server/bin/server-reboot, E-076).
     */
    public static function serverReboot(): self
    {
        return new self(ServerScript::ServerReboot, []);
    }

    /**
     * Creates a PHP website or repairs it (server/bin/site-create). With
     * $tls, Let's Encrypt issues a certificate and the site runs on HTTPS
     * with HTTP/3 (E-035).
     *
     * @param  array{memory: int, upload: int, time: int, processes: int, errors: bool}|null  $settings
     */
    public static function siteCreate(string $domain, string $user, bool $tls, bool $www = false, ?string $previousDomain = null, bool $existingOnly = false, ?string $php = null, ?array $settings = null): self
    {
        if (! Domain::isValid($domain) || ($www && ! Site::wwwPossible($domain))
            || ($previousDomain !== null && (! Domain::isValid($previousDomain) || $previousDomain === $domain))) {
            throw new InvalidArgumentException('Invalid domain.');
        }
        self::assertSiteUser($user);
        // The form only; the panel and the server check which versions are offered.
        if ($php !== null && preg_match('/^[0-9]{1,2}\.[0-9]{1,2}$/', $php) !== 1) {
            throw new InvalidArgumentException('Invalid PHP version.');
        }
        // PHP settings (E-173), as App\Sites\PhpSettings::effective() gives them.
        $settingArgs = [];
        if ($settings !== null) {
            foreach (['memory', 'upload', 'time', 'processes'] as $key) {
                if (! is_int($settings[$key] ?? null) || $settings[$key] < 1 || $settings[$key] > 99999) {
                    throw new InvalidArgumentException('Invalid PHP setting.');
                }
                $settingArgs[] = '--php-'.$key;
                $settingArgs[] = (string) $settings[$key];
            }
            $settingArgs[] = '--php-errors';
            $settingArgs[] = ($settings['errors'] ?? false) ? 'on' : 'off';
        }

        return new self(ServerScript::SiteCreate, [
            '--domain', $domain,
            '--type', Site::TYPE_PHP,
            '--user', $user,
            ...($tls ? ['--tls'] : []),
            // www.<domain> sends everything to the domain (E-110).
            ...($www ? ['--www'] : []),
            // The site moves from this domain to $domain (E-112).
            ...($previousDomain !== null ? ['--previous-domain', $previousDomain] : []),
            // Only rewrite a website that is there; a missing one is rebuilt from its backup (E-171).
            ...($existingOnly ? ['--existing'] : []),
            // Without --php the website keeps its version (E-172).
            ...($php !== null ? ['--php', $php] : []),
            // Without them the website keeps its settings (E-173).
            ...$settingArgs,
        ]);
    }

    /**
     * Deletes a website with all files for good (server/bin/site-delete).
     */
    public static function siteDelete(string $user): self
    {
        self::assertSiteUser($user);

        return new self(ServerScript::SiteDelete, ['--user', $user]);
    }

    /**
     * Writes all cron jobs of a website (server/bin/site-cron, E-088). The
     * jobs go on stdin, one per line: time fields, tab, command.
     *
     * @param  list<array{schedule: string, command: string}>  $jobs
     */
    public static function siteCron(string $user, array $jobs): self
    {
        self::assertSiteUser($user);
        $lines = '';
        foreach ($jobs as $job) {
            if (! CronJob::validSchedule($job['schedule']) || ! CronJob::validCommand($job['command'])) {
                throw new InvalidArgumentException('Invalid cron job.');
            }
            $lines .= CronJob::normalizeSchedule($job['schedule'])."\t".$job['command']."\n";
        }

        return new self(ServerScript::SiteCron, ['--user', $user], $lines);
    }

    /**
     * Writes the own nginx rules of a website (server/bin/site-nginx,
     * E-184). All rules go on stdin, one per line: "header", tab, name,
     * tab, value, or "path", tab, path. Checked here and on the server.
     *
     * @param  list<array{name: string, value: string}>  $headers
     * @param  list<string>  $paths
     */
    public static function siteNginx(string $user, array $headers, array $paths): self
    {
        self::assertSiteUser($user);
        $lines = '';
        foreach ($headers as $header) {
            if (! WebserverRules::validHeaderName($header['name']) || ! WebserverRules::validHeaderValue($header['value'])) {
                throw new InvalidArgumentException('Invalid header.');
            }
            $lines .= "header\t".$header['name']."\t".$header['value']."\n";
        }
        foreach ($paths as $path) {
            if (! WebserverRules::validPath($path)) {
                throw new InvalidArgumentException('Invalid path.');
            }
            $lines .= "path\t".$path."\n";
        }

        return new self(ServerScript::SiteNginx, ['--user', $user], $lines);
    }

    /**
     * The end of a server log (server/bin/server-logs, E-087).
     */
    public static function serverLogs(string $log, int $lines): self
    {
        if (! in_array($log, ['auth', 'fail2ban', 'nginx', 'system'], true) || $lines < 1 || $lines > 1000) {
            throw new InvalidArgumentException('Invalid log or number of lines.');
        }

        return new self(ServerScript::ServerLogs, ['--log', $log, '--lines', (string) $lines]);
    }

    /**
     * The end of a website's log (server/bin/site-logs, E-086).
     */
    public static function siteLogs(string $user, string $log, int $lines): self
    {
        self::assertSiteUser($user);
        if (! in_array($log, ['access', 'error', 'php', 'cron'], true) || $lines < 1 || $lines > 1000) {
            throw new InvalidArgumentException('Invalid log or number of lines.');
        }

        return new self(ServerScript::SiteLogs, ['--user', $user, '--log', $log, '--lines', (string) $lines]);
    }

    /**
     * Suspends a website or brings it back (server/bin/site-suspend and
     * site-unsuspend, E-127).
     */
    public static function siteSuspend(string $user, bool $suspend = true): self
    {
        self::assertSiteUser($user);
        if ($user === 'ow10000') {
            throw new InvalidArgumentException('The panel cannot be suspended.');
        }

        return new self($suspend ? ServerScript::SiteSuspend : ServerScript::SiteUnsuspend, ['--user', $user]);
    }

    /**
     * Storage of all websites on a server (server/bin/site-usage, E-126).
     */
    public static function siteUsage(): self
    {
        return new self(ServerScript::SiteUsage, []);
    }

    /**
     * Hard limit for the files of one website in KiB, 0 for none
     * (server/bin/site-quota, E-126).
     */
    public static function siteQuota(string $user, int $kib): self
    {
        self::assertSiteUser($user);
        if ($user === 'ow10000' || $kib < 0 || $kib > 17_179_869_184) {
            throw new InvalidArgumentException('Invalid quota.');
        }

        return new self(ServerScript::SiteQuota, ['--user', $user, '--kib', (string) $kib]);
    }

    /**
     * Visitors and page views of a website per day from its access log
     * (server/bin/site-stats, E-148): today and the days before.
     */
    public static function siteStats(string $user, int $days): self
    {
        self::assertSiteUser($user);
        if ($days < 1 || $days > 14) {
            throw new InvalidArgumentException('Invalid number of days.');
        }

        return new self(ServerScript::SiteStats, ['--user', $user, '--days', (string) $days]);
    }

    /**
     * SFTP access of a website (server/bin/site-sftp, E-089). The whole state
     * goes to the server each time: password and keys on stdin, so they
     * appear neither in the process list nor in the task.
     *
     * @param  list<string>  $keys  public keys as checked by SftpKey::parse()
     */
    public static function siteSftp(string $user, bool $enabled, #[\SensitiveParameter] ?string $password = null, array $keys = []): self
    {
        self::assertSiteUser($user);
        if (! $enabled) {
            return new self(ServerScript::SiteSftp, ['--user', $user, '--state', 'off']);
        }

        $lines = '';
        if ($password !== null) {
            if (! SftpKey::validPassword($password)) {
                throw new InvalidArgumentException('Invalid SFTP password.');
            }
            $lines .= "password\t".$password."\n";
        }
        foreach ($keys as $key) {
            if (SftpKey::parse($key) === null) {
                throw new InvalidArgumentException('Invalid SSH key.');
            }
            $lines .= "key\t".$key."\n";
        }

        return new self(ServerScript::SiteSftp, ['--user', $user, '--state', 'on'], $lines);
    }

    /**
     * The own firewall rules of a server (server/bin/firewall-rules, E-090),
     * always the whole list on stdin. Lines come from FirewallRule::lines().
     *
     * @param  list<string>  $lines
     */
    public static function firewallRules(array $lines): self
    {
        foreach ($lines as $line) {
            if (! preg_match('#^(tcp|udp)\t\d{1,5}(-\d{1,5})?\t(any|[0-9a-f:.]+/\d{1,3})$#', $line)) {
                throw new InvalidArgumentException('Invalid firewall rule.');
            }
        }

        return new self(ServerScript::FirewallRules, [], $lines === [] ? '' : implode("\n", $lines)."\n");
    }

    /**
     * Confirms a firewall change over a new SSH connection (E-044).
     */
    public static function firewallConfirm(): self
    {
        return new self(ServerScript::FirewallConfirm);
    }

    /**
     * Banned addresses and exceptions of fail2ban (E-090), read-only.
     */
    public static function fail2banStatus(): self
    {
        return new self(ServerScript::ServerFail2ban, ['--action', 'status']);
    }

    /**
     * The addresses fail2ban never bans, the whole list on stdin.
     *
     * @param  list<string>  $addresses  normalized by App\Support\IpNetwork
     */
    public static function fail2banIgnore(array $addresses): self
    {
        foreach ($addresses as $address) {
            if (IpNetwork::normalize($address) !== $address) {
                throw new InvalidArgumentException('Invalid address.');
            }
        }

        return new self(ServerScript::ServerFail2ban, ['--action', 'ignore'], $addresses === [] ? '' : implode("\n", $addresses)."\n");
    }

    /**
     * The whole set of blocklist entries and exceptions of a server (E-170),
     * networks with prefix as BlocklistParser and IpNetwork write them.
     *
     * @param  list<string>  $block
     * @param  list<string>  $allow
     */
    public static function serverBlocklist(array $block, array $allow): self
    {
        $lines = [];
        foreach (['block' => $block, 'allow' => $allow] as $kind => $networks) {
            foreach ($networks as $network) {
                if (! preg_match('#^[0-9a-f:.]{2,39}/\d{1,3}$#', $network)) {
                    throw new InvalidArgumentException('Invalid blocklist entry.');
                }
                $lines[] = $kind."\t".$network;
            }
        }

        return new self(ServerScript::ServerBlocklist, [], $lines === [] ? '' : implode("\n", $lines)."\n");
    }

    /**
     * Which addresses the blocklists dropped how often (E-170); reads only.
     */
    public static function blocklistStats(): self
    {
        return new self(ServerScript::ServerBlocklist, ['--stats']);
    }

    public static function fail2banUnban(string $address): self
    {
        if (IpNetwork::normalize($address) !== $address || str_contains($address, '/')) {
            throw new InvalidArgumentException('Invalid address.');
        }

        return new self(ServerScript::ServerFail2ban, ['--action', 'unban', '--ip', $address]);
    }

    /**
     * Connects a server to the Storage Box (server/bin/backup-setup, E-096).
     * Account password (only for the first key) and repository password go
     * through stdin.
     */
    public static function backupSetup(string $host, string $account, string $path, #[\SensitiveParameter] string $repoPassword, #[\SensitiveParameter] ?string $accountPassword = null): self
    {
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host)
            || ! preg_match('/^u[0-9]{3,10}(-sub[0-9]{1,4})?$/', $account)
            || ! preg_match('#^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$#', $path)
            || ! preg_match('/^[A-Za-z0-9]{32,128}$/', $repoPassword)
            || ($accountPassword !== null && (strlen($accountPassword) > 256 || preg_match('/[\x00-\x1f\x7f]/', $accountPassword)))) {
            throw new InvalidArgumentException('Invalid backup target.');
        }
        $stdin = ($accountPassword !== null && $accountPassword !== '' ? "password\t".$accountPassword."\n" : '')."repo\t".$repoPassword."\n";

        return new self(ServerScript::BackupSetup, ['--host', $host, '--account', $account, '--path', $path], $stdin);
    }

    /**
     * Backs up one website (server/bin/site-backup, E-096).
     *
     * @param  array{hourly?: int, daily: int, weekly: int, monthly: int}  $keep
     */
    public static function siteBackup(string $user, bool $manual, array $keep): self
    {
        self::assertSiteUser($user);
        foreach ($keep as $value) {
            if ($value < 0 || $value > 999) {
                throw new InvalidArgumentException('Invalid retention.');
            }
        }
        $arguments = ['--user', $user];
        if ($manual) {
            $arguments[] = '--manual';
        }
        if (($keep['hourly'] ?? 0) > 0) {
            array_push($arguments, '--keep-hourly', (string) $keep['hourly']);
        }
        array_push($arguments, '--keep-daily', (string) max(1, $keep['daily']), '--keep-weekly', (string) $keep['weekly'], '--keep-monthly', (string) $keep['monthly']);

        return new self(ServerScript::SiteBackup, $arguments);
    }

    public static function siteBackups(string $user): self
    {
        self::assertSiteUser($user);

        return new self(ServerScript::SiteBackups, ['--user', $user]);
    }

    /**
     * Backs up the panel itself on the server it runs on
     * (server/bin/panel-backup, E-099).
     *
     * @param  array{hourly?: int, daily: int, weekly: int, monthly: int}  $keep
     */
    public static function panelBackup(bool $manual, array $keep): self
    {
        foreach ($keep as $value) {
            if ($value < 0 || $value > 999) {
                throw new InvalidArgumentException('Invalid retention.');
            }
        }

        return new self(ServerScript::PanelBackup, array_merge($manual ? ['--manual'] : [], ($keep['hourly'] ?? 0) > 0 ? ['--keep-hourly', (string) $keep['hourly']] : [], [
            '--keep-daily', (string) $keep['daily'], '--keep-weekly', (string) $keep['weekly'], '--keep-monthly', (string) $keep['monthly'],
        ]));
    }

    /**
     * Lists the backups of the panel itself (server/bin/panel-backups, E-099).
     */
    public static function panelBackups(): self
    {
        return new self(ServerScript::PanelBackups, []);
    }

    /**
     * The files of one backup of the panel itself, without their content
     * (server/bin/panel-backup-files, E-099).
     */
    public static function panelBackupFiles(string $snapshot): self
    {
        self::assertSnapshot($snapshot);

        return new self(ServerScript::PanelBackupFiles, ['--snapshot', $snapshot]);
    }

    /**
     * Entries of one directory below htdocs and the database dumps in a
     * snapshot of a website (server/bin/site-backup-files, E-098).
     */
    public static function siteBackupFiles(string $user, string $snapshot, string $path = ''): self
    {
        self::assertSiteUser($user);
        self::assertSnapshot($snapshot);
        self::assertSitePath($path);

        return new self(ServerScript::SiteBackupFiles, array_merge(['--user', $user, '--snapshot', $snapshot], $path !== '' ? ['--path', $path] : []));
    }

    /**
     * Restores a website from a snapshot after a safety backup
     * (server/bin/site-restore, E-098). Scopes: all, files, path (with a
     * path below htdocs), databases, database (with its name). The snapshot
     * "latest" is the newest scheduled or manual backup, chosen by the
     * server when the task runs (E-106).
     */
    public static function siteRestore(string $user, string $snapshot, string $scope, string $path = '', string $database = ''): self
    {
        self::assertSiteUser($user);
        if ($snapshot !== self::LATEST) {
            self::assertSnapshot($snapshot);
        }
        $arguments = ['--user', $user, '--snapshot', $snapshot, '--scope', $scope];
        match ($scope) {
            'all', 'files', 'databases' => $path === '' && $database === '' ?: throw new InvalidArgumentException('Unexpected path or database.'),
            'path' => $path !== '' && $database === '' ?: throw new InvalidArgumentException('Missing path.'),
            'database' => $path === '' && preg_match('/^'.preg_quote($user, '/').'_[a-z0-9][a-z0-9_]{0,15}$/', $database) === 1 ?: throw new InvalidArgumentException('Invalid database.'),
            default => throw new InvalidArgumentException('Invalid scope.'),
        };
        if ($scope === 'path') {
            self::assertSitePath($path);
            $arguments = [...$arguments, '--path', $path];
        }
        if ($scope === 'database') {
            $arguments = [...$arguments, '--database', $database];
        }

        return new self(ServerScript::SiteRestore, $arguments);
    }

    /**
     * A restic snapshot ID, short (8) or full (64 hex digits).
     */
    private static function assertSnapshot(string $snapshot): void
    {
        if (! preg_match('/^[0-9a-f]{8,64}$/', $snapshot)) {
            throw new InvalidArgumentException('Invalid snapshot.');
        }
    }

    /**
     * A path below htdocs as site-backup-files and site-restore take it:
     * relative, without "." or ".." parts or control characters.
     */
    public static function validSitePath(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        if (strlen($path) > 1024 || str_starts_with($path, '/') || str_ends_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    private static function assertSitePath(string $path): void
    {
        if (! self::validSitePath($path)) {
            throw new InvalidArgumentException('Invalid path.');
        }
    }

    /**
     * ow10001 to ow59999; ow10000 is the panel itself (E-041).
     */
    private static function assertSiteUser(string $user): void
    {
        if (! preg_match('/^ow([1-5][0-9]{4})$/', $user, $match)
            || (int) $match[1] < Site::USER_MIN || (int) $match[1] > Site::USER_MAX) {
            throw new InvalidArgumentException('Invalid site user.');
        }
    }

    /**
     * Command that the agent runs on the server, e.g.
     * sudo -n '/opt/owpanel/bin/panel-test' '--steps' '3'
     *
     * "-n" makes sudo fail instead of waiting for a password.
     */
    public function commandLine(): string
    {
        return implode(' ', [
            'sudo', '-n',
            escapeshellarg($this->script->path()),
            ...array_map(escapeshellarg(...), $this->arguments),
        ]);
    }
}
