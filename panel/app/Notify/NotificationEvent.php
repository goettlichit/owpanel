<?php

namespace App\Notify;

/**
 * Events an administrator can be notified about (E-101). Problems are sent
 * once when they appear and once when they are gone; the others, such as a
 * finished backup, once when they happen (E-104).
 */
enum NotificationEvent: string
{
    case BackupFailed = 'backup_failed';
    case SnapshotFailed = 'snapshot_failed';
    case ServerOffline = 'server_offline';
    case RebootRequired = 'reboot_required';
    case DiskFull = 'disk_full';
    case CertificateExpiring = 'certificate_expiring';
    case StorageFull = 'storage_full';
    case BackupSucceeded = 'backup_succeeded';
    case SnapshotSucceeded = 'snapshot_succeeded';
    case RestoreFinished = 'restore_finished';
    case ServerRestarted = 'server_restarted';
    case UpdatesInstalled = 'updates_installed';
    case UpdatesAvailable = 'updates_available';
    case AutoUpdatesOff = 'auto_updates_off';
    case PanelUpdateAvailable = 'panel_update_available';
    case PanelUpdated = 'panel_updated';
    case PanelUpdateFailed = 'panel_update_failed';
    case ScriptsOutdated = 'scripts_outdated';
    case ServiceDown = 'service_down';
    case StorageBoxFull = 'storage_box_full';
    case HeartbeatFailed = 'heartbeat_failed';
    case NewDeviceLogin = 'new_device_login';
    case LoginLockout = 'login_lockout';
    case BlocklistFailed = 'blocklist_failed';
    case TaskFailed = 'task_failed';

    /**
     * Groups on the settings page.
     *
     * @return array<string, list<self>>
     */
    public static function groups(): array
    {
        return [
            'backup' => [self::BackupFailed, self::SnapshotFailed, self::StorageBoxFull, self::BackupSucceeded, self::SnapshotSucceeded, self::RestoreFinished],
            'availability' => [self::ServerOffline, self::ServiceDown, self::HeartbeatFailed, self::ServerRestarted],
            'maintenance' => [self::RebootRequired, self::DiskFull, self::StorageFull, self::CertificateExpiring, self::UpdatesAvailable, self::AutoUpdatesOff, self::UpdatesInstalled, self::PanelUpdateAvailable, self::PanelUpdated, self::PanelUpdateFailed, self::ScriptsOutdated],
            'security' => [self::NewDeviceLogin, self::LoginLockout, self::BlocklistFailed],
            'tasks' => [self::TaskFailed],
        ];
    }

    public function label(): string
    {
        return __('notifications.events.'.$this->value.'.label');
    }
}
