<?php

namespace App\Enums;

/**
 * The allowlist of server scripts (E-051, docs/SICHERHEIT.md "Rechte auf den
 * Servern"). The panel runs nothing else on a server. The cases match the
 * sudo rules in server/templates/sudoers/owpanel-agent; a test compares both.
 */
enum ServerScript: string
{
    case DbCreate = 'db-create';
    case DbDelete = 'db-delete';
    case DbExport = 'db-export';
    case DbImport = 'db-import';
    case DbQuery = 'db-query';
    case FirewallConfirm = 'firewall-confirm';
    case FirewallRules = 'firewall-rules';
    case GatewaySite = 'gateway-site';
    case PanelTest = 'panel-test';
    case PanelUpdate = 'panel-update';
    case ServerScriptsUpdate = 'server-scripts-update';
    case ServerLogs = 'server-logs';
    case ServerProvision = 'server-provision';
    case ServerReboot = 'server-reboot';
    case ServerStatus = 'server-status';
    case ServerTimezone = 'server-timezone';
    case ServerUpdates = 'server-updates';
    case ServerUpgrade = 'server-upgrade';
    case ServerSsh = 'server-ssh';
    case SiteCertificates = 'site-certificates';
    case SiteCreate = 'site-create';
    case SiteCron = 'site-cron';
    case SiteDelete = 'site-delete';
    case SiteLogs = 'site-logs';
    case SiteNginx = 'site-nginx';
    case SiteSftp = 'site-sftp';
    case SiteStats = 'site-stats';
    case SiteSuspend = 'site-suspend';
    case SiteUnsuspend = 'site-unsuspend';
    case SiteUsage = 'site-usage';
    case SiteQuota = 'site-quota';
    case BackupSetup = 'backup-setup';
    case SiteBackup = 'site-backup';
    case SiteBackups = 'site-backups';
    case SiteBackupFiles = 'site-backup-files';
    case SiteRestore = 'site-restore';
    case PanelBackup = 'panel-backup';
    case PanelBackups = 'panel-backups';
    case PanelBackupFiles = 'panel-backup-files';
    case ServerFail2ban = 'server-fail2ban';
    case ServerBlocklist = 'server-blocklist';

    public const DIRECTORY = '/opt/owpanel/bin';

    public function path(): string
    {
        return self::DIRECTORY.'/'.$this->value;
    }

    /**
     * Whether the script may run while the server scripts differ from the
     * panel's version (E-167): updating, reading, backups, updates of the
     * system, restarts and confirming the firewall. Everything else waits
     * until the panel has brought the scripts up to date.
     */
    public function runsWhenOutdated(): bool
    {
        return in_array($this, [
            self::ServerScriptsUpdate, self::PanelUpdate, self::PanelTest,
            self::ServerStatus, self::ServerUpdates, self::ServerLogs, self::SiteLogs,
            self::ServerUpgrade, self::ServerReboot, self::FirewallConfirm,
            self::SiteBackup, self::SiteBackups, self::SiteBackupFiles,
            self::PanelBackup, self::PanelBackups, self::PanelBackupFiles,
        ], true);
    }

    /**
     * Seconds a run may take before the panel gives up. Must stay below the
     * queue's retry_after (config/queue.php).
     */
    public function timeout(): int
    {
        return match ($this) {
            // Installs all packages on a fresh server.
            self::ServerProvision => 3600,
            // Let's Encrypt may take a while.
            self::SiteCreate, self::SiteDelete, self::GatewaySite => 600,
            self::FirewallConfirm, self::FirewallRules, self::ServerFail2ban, self::ServerBlocklist, self::PanelTest, self::ServerTimezone, self::SiteCron, self::SiteNginx, self::SiteSftp => 120,
            self::DbCreate, self::DbDelete => 180,
            // Runs while the user waits; the script stops itself after 35 s.
            self::DbQuery => 40,
            // Large databases take a while; both stream their data.
            self::DbExport, self::DbImport => 1800,
            // Runs every minute and measures CPU for one second.
            self::ServerStatus, self::SiteLogs, self::ServerLogs => 20,
            // Reads up to 14 days of access logs (E-148).
            self::SiteStats => 120,
            self::SiteSuspend, self::SiteUnsuspend => 120,
            // du over all websites on a server without quotas (E-126).
            self::SiteUsage => 600,
            self::SiteQuota => 30,
            // Read only; apt may need a moment to simulate the upgrade.
            self::ServerUpdates, self::SiteCertificates => 120,
            // Returns at once, the reboot follows 15 seconds later.
            self::ServerReboot, self::ServerSsh => 60,
            // apt-get dist-upgrade, plus up to 10 minutes waiting for the apt lock (E-154).
            self::ServerUpgrade => 2400,
            // Installs a key and may create the repository (E-096).
            self::BackupSetup => 300,
            // Dumps and uploads a whole website; below the job timeout.
            self::SiteBackup => 3600,
            // Read only, through the query worker.
            self::SiteBackups, self::SiteBackupFiles, self::PanelBackups, self::PanelBackupFiles => 40,
            // Dumps the panel database and uploads it (E-099).
            self::PanelBackup => 3600,
            // A safety backup first, then the restore (E-098).
            self::SiteRestore => 3600,
            // Downloads and checks a release, then starts the installer in
            // the background and returns (E-185).
            self::PanelUpdate => 900,
            // Receives a release archive over SSH and installs its scripts
            // (E-167).
            self::ServerScriptsUpdate => 900,
        };
    }
}
