<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CustomerAccessController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPocketIdController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DatabaseToolController;
use App\Http\Controllers\DatabaseTransferController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\My\SiteLogController as MySiteLogController;
use App\Http\Controllers\My\SiteSftpController as MySiteSftpController;
use App\Http\Controllers\MySitesController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OidcController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PanelWorkerController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\ServerBackupController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerDnsController;
use App\Http\Controllers\ServerFirewallController;
use App\Http\Controllers\ServerSnapshotController;
use App\Http\Controllers\ServerSshController;
use App\Http\Controllers\ServerTaskController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SiteBackupController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteCronController;
use App\Http\Controllers\SiteDnsController;
use App\Http\Controllers\SiteLogController;
use App\Http\Controllers\SiteSftpController;
use App\Http\Controllers\SiteSuspensionController;
use App\Http\Controllers\SiteWebserverController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\UserController;
use App\Oidc\OidcSettings;
use Illuminate\Support\Facades\Route;

// Login, logout, two-factor authentication and password change come from
// Laravel Fortify (config/fortify.php, E-050).

// Language and colour scheme also work on the login page.
Route::middleware('throttle:30,1')->group(function () {
    Route::post('/preferences/locale', [PreferencesController::class, 'locale'])->name('preferences.locale');
    Route::post('/preferences/theme', [PreferencesController::class, 'theme'])->name('preferences.theme');
});

// Login with Pocket ID (E-114). The callback serves login and linking.
Route::get('/auth/oidc/redirect', [OidcController::class, 'redirect'])
    ->middleware(['guest', 'throttle:10,1'])->name('oidc.redirect');
Route::get(OidcSettings::CALLBACK_PATH, [OidcController::class, 'callback'])
    ->middleware('throttle:20,1')->name('oidc.callback');

// Invitation link of a customer: sets the first password (E-128). Also
// reachable while logged in, so an administrator can try a link; it logs
// nobody in.
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/invitation/{user}/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation/{user}/{token}', [InvitationController::class, 'store'])->name('invitation.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    // Customer area (E-124, E-143): own navigation, own routes, only for
    // customers. The policies check that a website is the customer's own.
    Route::middleware('role:customer')->prefix('/my')->name('my.')->group(function () {
        Route::get('/sites', [MySitesController::class, 'index'])->name('sites');
        Route::get('/sites/{site}', [MySitesController::class, 'show'])->name('sites.show');
        Route::get('/sites/{site}/dns', [MySitesController::class, 'dns'])->middleware('throttle:30,1')->name('sites.dns');
        Route::post('/sites/{site}/dns/check', [MySitesController::class, 'checkDns'])->middleware('throttle:10,1')->name('sites.dns.check');
        Route::get('/sites/{site}/logs', [MySiteLogController::class, 'show'])->middleware('throttle:30,1')->name('sites.logs');
        Route::get('/sites/{site}/php', [SiteController::class, 'phpPage'])->name('sites.php.show');
        Route::get('/sites/{site}/sftp', [MySiteSftpController::class, 'index'])->name('sites.sftp');
        Route::get('/sites/{site}/sftp/password', [MySiteSftpController::class, 'reveal'])->name('sites.sftp.reveal');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/sites/{site}/sftp/toggle', [MySiteSftpController::class, 'toggle'])->name('sites.sftp.toggle');
            Route::post('/sites/{site}/sftp/password', [MySiteSftpController::class, 'password'])->name('sites.sftp.password');
            Route::post('/sites/{site}/sftp/keys', [MySiteSftpController::class, 'storeKey'])->name('sites.sftp.keys.store');
            Route::delete('/sites/{site}/sftp/keys/{sftpKey}', [MySiteSftpController::class, 'destroyKey'])->name('sites.sftp.keys.destroy');
        });
        // These start site-create on the server, like for administrators.
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/sites/{site}/certificate', [SiteController::class, 'requestCertificate'])->name('sites.certificate');
            Route::post('/sites/{site}/www', [SiteController::class, 'updateWww'])->name('sites.www');
            Route::post('/sites/{site}/php', [SiteController::class, 'updatePhp'])->name('sites.php');
            Route::post('/sites/{site}/php/settings', [SiteController::class, 'updatePhpSettings'])->name('sites.php.settings');
        });
        // Cron jobs and databases of the own website (E-144), with the same
        // controllers as the administration.
        Route::get('/sites/{site}/cron', [SiteCronController::class, 'index'])->name('sites.cron');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/sites/{site}/cron', [SiteCronController::class, 'store'])->name('sites.cron.store');
            Route::put('/sites/{site}/cron/{cronJob}', [SiteCronController::class, 'update'])->name('sites.cron.update');
            Route::delete('/sites/{site}/cron/{cronJob}', [SiteCronController::class, 'destroy'])->name('sites.cron.destroy');
        });
        // Backups of the own website within the daily limit of the package (E-145).
        Route::get('/sites/{site}/backups', [SiteBackupController::class, 'index'])->middleware('throttle:30,1')->name('sites.backups');
        Route::post('/sites/{site}/backups', [SiteBackupController::class, 'store'])->middleware('throttle:10,1')->name('sites.backups.store');
        Route::get('/sites/{site}/backups/{snapshot}', [SiteBackupController::class, 'show'])->where('snapshot', '[0-9a-f]{8}')->middleware('throttle:30,1')->name('sites.backups.show');
        Route::post('/sites/{site}/backups/{snapshot}/restore', [SiteBackupController::class, 'restore'])->where('snapshot', '[0-9a-f]{8}')->middleware('throttle:10,1')->name('sites.backups.restore');
        Route::get('/sites/{site}/databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::get('/databases/{database}', [DatabaseController::class, 'show'])->name('databases.show');
        Route::get('/databases/{database}/password', [DatabaseController::class, 'reveal'])->name('databases.reveal');
        Route::get('/databases/{database}/transfer', [DatabaseTransferController::class, 'show'])->name('databases.transfer');
        Route::get('/databases/exports/{task}', [DatabaseTransferController::class, 'download'])->name('databases.download');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/sites/{site}/databases', [DatabaseController::class, 'store'])->name('databases.store');
            Route::post('/databases/{database}/password', [DatabaseController::class, 'changePassword'])->name('databases.password');
            Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
            Route::post('/databases/{database}/export', [DatabaseTransferController::class, 'export'])->name('databases.export');
            Route::post('/databases/{database}/import', [DatabaseTransferController::class, 'import'])->name('databases.import');
        });
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/databases/{database}/tables', [DatabaseToolController::class, 'tables'])->name('databases.tables');
            Route::get('/databases/{database}/table', [DatabaseToolController::class, 'table'])->name('databases.table');
            Route::get('/databases/{database}/sql', [DatabaseToolController::class, 'console'])->name('databases.sql');
            Route::post('/databases/{database}/sql', [DatabaseToolController::class, 'run'])->name('databases.run');
        });
    });
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    // No second password prompt anywhere (E-135).
    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');
    // Linking needs an active login (docs/SICHERHEIT.md).
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/account/oidc', [OidcController::class, 'link'])->name('account.oidc.link');
        Route::delete('/account/oidc', [OidcController::class, 'unlink'])->name('account.oidc.unlink');
    });
    // Notifications by mail and push (E-101).
    Route::get('/account/notifications', [NotificationController::class, 'show'])->name('account.notifications');
    Route::middleware('throttle:30,1')->group(function () {
        Route::put('/account/notifications', [NotificationController::class, 'update'])->name('account.notifications.update');
        Route::post('/account/notifications/devices', [NotificationController::class, 'subscribe'])->name('account.notifications.subscribe');
        Route::delete('/account/notifications/devices/{pushSubscription}', [NotificationController::class, 'destroy'])->name('account.notifications.destroy');
    });
    Route::post('/account/notifications/test', [NotificationController::class, 'test'])->middleware('throttle:5,1')->name('account.notifications.test');
    // Administration (E-143): only administrators, in addition to the policies.
    Route::middleware('role:admin')->group(function () {
        // Visitors of the websites from their access logs (E-148).
        Route::get('/system/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::get('/audit-log/{entry}', [AuditLogController::class, 'show'])->name('audit-log.show');
        // Settings of the panel (E-091).
        // Asks for the password again: the mail server decides where password
        // reset links go (E-094).
        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])->middleware('throttle:30,1')->name('settings.update');
        // Own pages for mail, Pocket ID and external services (E-118, E-123).
        Route::get('/settings/mail', [SettingController::class, 'mail'])->name('settings.mail');
        Route::get('/settings/oidc', [SettingController::class, 'oidc'])->name('settings.oidc');
        Route::get('/settings/services', [SettingController::class, 'services'])->name('settings.services');
        Route::put('/settings/mail', [SettingController::class, 'updateMail'])->middleware('throttle:30,1')->name('settings.mail.update');
        Route::post('/settings/mail/test', [SettingController::class, 'testMail'])->name('settings.mail.test');
        // Login with Pocket ID (E-114); saving checks the provider first.
        Route::middleware('throttle:10,1')->group(function () {
            Route::put('/settings/oidc', [SettingController::class, 'updateOidc'])->name('settings.oidc.update');
            Route::post('/settings/oidc/test', [SettingController::class, 'testOidc'])->name('settings.oidc.test');
        });
        Route::get('/settings/backup', [SettingController::class, 'backup'])->name('settings.backup');
        Route::post('/settings/backup/restored/dismiss', [SettingController::class, 'dismissRestored'])->middleware('throttle:10,1')->name('settings.backup.restored.dismiss');
        Route::post('/settings/backup/export', [SettingController::class, 'exportBackupKeys'])->middleware('throttle:5,1')->name('settings.backup.export');
        Route::put('/settings/hetzner', [SettingController::class, 'updateHetzner'])->middleware('throttle:10,1')->name('settings.hetzner.update');
        Route::put('/settings/hetzner-dns', [SettingController::class, 'updateHetznerDns'])->middleware('throttle:10,1')->name('settings.hetzner_dns.update');
        Route::put('/settings/heartbeat', [SettingController::class, 'updateHeartbeat'])->middleware('throttle:10,1')->name('settings.heartbeat.update');
        Route::put('/settings/pocket-id-api', [SettingController::class, 'updatePocketIdApi'])->middleware('throttle:10,1')->name('settings.pocket_id_api.update');

        // Panel accounts (E-081).
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::put('/users/{user}/oidc', [UserController::class, 'updateOidc'])->name('users.oidc');
        });

        Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('/sites/create', [SiteController::class, 'create'])->name('sites.create');
        Route::get('/sites/{site}', [SiteController::class, 'show'])->name('sites.show');
        Route::patch('/sites/{site}/customer', [SiteController::class, 'assignCustomer'])->middleware('throttle:30,1')->name('sites.customer');
        // Own pages in the website's left menu (E-137).
        Route::get('/sites/{site}/domain', [SiteController::class, 'domainPage'])->name('sites.domain.show');
        Route::get('/sites/{site}/certificate', [SiteController::class, 'certificatePage'])->name('sites.certificate.show');
        Route::get('/sites/{site}/php', [SiteController::class, 'phpPage'])->name('sites.php.show');

        // Customers (E-084).
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware('throttle:30,1')->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        // Own pages in the customer's left menu, like the pages of a website.
        Route::get('/customers/{customer}/details', [CustomerController::class, 'details'])->name('customers.details');
        Route::get('/customers/{customer}/package', [CustomerController::class, 'package'])->name('customers.package.show');
        Route::get('/customers/{customer}/access', [CustomerController::class, 'access'])->name('customers.access');
        Route::get('/customers/{customer}/delete', [CustomerController::class, 'confirmDelete'])->name('customers.delete');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('throttle:30,1')->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('throttle:30,1')->name('customers.destroy');
        Route::patch('/customers/{customer}/package', [CustomerController::class, 'assignPackage'])->middleware('throttle:30,1')->name('customers.package');
        // Access of the customer to the customer area (E-128).
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/customers/{customer}/access', [CustomerAccessController::class, 'store'])->name('customers.access.store');
            Route::post('/customers/{customer}/access/invite', [CustomerAccessController::class, 'resend'])->name('customers.access.invite');
            Route::post('/customers/{customer}/access/password', [CustomerAccessController::class, 'resetPassword'])->name('customers.access.password');
            Route::post('/customers/{customer}/access/lock', [CustomerAccessController::class, 'lock'])->name('customers.access.lock');
            Route::post('/customers/{customer}/access/unlock', [CustomerAccessController::class, 'unlock'])->name('customers.access.unlock');
            Route::delete('/customers/{customer}/access', [CustomerAccessController::class, 'destroy'])->name('customers.access.destroy');
            // Pocket ID of the customer (E-130, E-138).
            Route::put('/customers/{customer}/pocket-id/allow', [CustomerPocketIdController::class, 'allow'])->name('customers.pocket_id.allow');
            Route::post('/customers/{customer}/pocket-id/search', [CustomerPocketIdController::class, 'search'])->name('customers.pocket_id.search');
            Route::post('/customers/{customer}/pocket-id/link', [CustomerPocketIdController::class, 'link'])->name('customers.pocket_id.link');
            Route::post('/customers/{customer}/pocket-id/create', [CustomerPocketIdController::class, 'create'])->name('customers.pocket_id.create');
            Route::post('/customers/{customer}/pocket-id/login-link', [CustomerPocketIdController::class, 'loginLink'])->name('customers.pocket_id.login_link');
            Route::post('/customers/{customer}/pocket-id/send-link', [CustomerPocketIdController::class, 'sendLink'])->name('customers.pocket_id.send_link');
            Route::delete('/customers/{customer}/pocket-id', [CustomerPocketIdController::class, 'unlink'])->name('customers.pocket_id.unlink');
        });
        // Packages with limits for a whole customer (E-125).
        Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('/packages/create', [PackageController::class, 'create'])->name('packages.create');
        Route::post('/packages', [PackageController::class, 'store'])->middleware('throttle:30,1')->name('packages.store');
        Route::get('/packages/{package}', [PackageController::class, 'edit'])->name('packages.edit');
        Route::put('/packages/{package}', [PackageController::class, 'update'])->middleware('throttle:30,1')->name('packages.update');
        Route::delete('/packages/{package}', [PackageController::class, 'destroy'])->middleware('throttle:30,1')->name('packages.destroy');
        // Reads a log through the query worker (E-086), so it is limited.
        Route::get('/sites/{site}/logs', [SiteLogController::class, 'show'])->middleware('throttle:30,1')->name('sites.logs');
        // DNS of a website, administrators only (E-116).
        Route::get('/sites/{site}/delete', [SiteController::class, 'confirmDestroy'])->name('sites.delete');
        // Suspension (E-127).
        // Own nginx rules of a website (E-184).
        Route::get('/sites/{site}/webserver', [SiteWebserverController::class, 'show'])->name('sites.webserver');
        Route::put('/sites/{site}/webserver', [SiteWebserverController::class, 'update'])->middleware('throttle:10,1')->name('sites.webserver.update');
        Route::get('/sites/{site}/suspension', [SiteSuspensionController::class, 'show'])->name('sites.suspension');
        Route::post('/sites/{site}/suspension', [SiteSuspensionController::class, 'store'])->middleware('throttle:10,1')->name('sites.suspension.store');
        Route::delete('/sites/{site}/suspension', [SiteSuspensionController::class, 'destroy'])->middleware('throttle:10,1')->name('sites.suspension.destroy');
        Route::get('/sites/{site}/dns', [SiteDnsController::class, 'show'])->middleware('throttle:30,1')->name('sites.dns');
        Route::post('/sites/{site}/dns/check', [SiteDnsController::class, 'check'])->middleware('throttle:10,1')->name('sites.dns.check');
        Route::get('/sites/{site}/dns/change', [SiteDnsController::class, 'confirm'])->middleware('throttle:30,1')->name('sites.dns.change');
        Route::post('/sites/{site}/dns/change', [SiteDnsController::class, 'apply'])->middleware('throttle:10,1')->name('sites.dns.apply');
        Route::get('/sites/{site}/tasks', [SiteLogController::class, 'tasks'])->name('sites.tasks');
        // Cron jobs (E-088); every change starts site-cron.
        Route::get('/sites/{site}/cron', [SiteCronController::class, 'index'])->name('sites.cron');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/sites/{site}/cron', [SiteCronController::class, 'store'])->name('sites.cron.store');
            Route::put('/sites/{site}/cron/{cronJob}', [SiteCronController::class, 'update'])->name('sites.cron.update');
            Route::delete('/sites/{site}/cron/{cronJob}', [SiteCronController::class, 'destroy'])->name('sites.cron.destroy');
        });
        // Backups of a website (E-096).
        Route::get('/sites/{site}/backups', [SiteBackupController::class, 'index'])->middleware('throttle:30,1')->name('sites.backups');
        Route::post('/sites/{site}/backups/toggle', [SiteBackupController::class, 'toggle'])->middleware('throttle:30,1')->name('sites.backups.toggle');
        Route::post('/sites/{site}/backups', [SiteBackupController::class, 'store'])->middleware('throttle:10,1')->name('sites.backups.store');
        Route::get('/sites/{site}/backups/{snapshot}', [SiteBackupController::class, 'show'])->where('snapshot', '[0-9a-f]{8}')->middleware('throttle:30,1')->name('sites.backups.show');
        Route::post('/sites/{site}/backups/{snapshot}/restore', [SiteBackupController::class, 'restore'])->where('snapshot', '[0-9a-f]{8}')->middleware('throttle:10,1')->name('sites.backups.restore');
        // Rebuild of a website the server lacks (E-106).
        Route::post('/sites/{site}/backups/rebuild', [SiteBackupController::class, 'rebuild'])->middleware('throttle:10,1')->name('sites.backups.rebuild');
        Route::post('/sites/{site}/backups/{snapshot}/rebuild', [SiteBackupController::class, 'rebuild'])->where('snapshot', '[0-9a-f]{8}')->middleware('throttle:10,1')->name('sites.backups.rebuild.snapshot');
        // SFTP access (E-089); every change starts site-sftp.
        Route::get('/sites/{site}/sftp', [SiteSftpController::class, 'index'])->name('sites.sftp');
        Route::get('/sites/{site}/sftp/password', [SiteSftpController::class, 'reveal'])->name('sites.sftp.reveal');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/sites/{site}/sftp/toggle', [SiteSftpController::class, 'toggle'])->name('sites.sftp.toggle');
            Route::post('/sites/{site}/sftp/password', [SiteSftpController::class, 'password'])->name('sites.sftp.password');
            Route::post('/sites/{site}/sftp/keys', [SiteSftpController::class, 'storeKey'])->name('sites.sftp.keys.store');
            Route::delete('/sites/{site}/sftp/keys/{sftpKey}', [SiteSftpController::class, 'destroyKey'])->name('sites.sftp.keys.destroy');
        });
        Route::get('/sites/{site}/databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::get('/databases/{database}', [DatabaseController::class, 'show'])->name('databases.show');
        // Plain-text password; every reveal is in the audit log (E-107).
        Route::get('/databases/{database}/password', [DatabaseController::class, 'reveal'])->name('databases.reveal');
        // Database export and import (E-082).
        Route::get('/databases/{database}/transfer', [DatabaseTransferController::class, 'show'])->name('databases.transfer');
        Route::get('/tasks/{task}/download', [DatabaseTransferController::class, 'download'])->name('tasks.download');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/databases/{database}/export', [DatabaseTransferController::class, 'export'])->name('databases.export');
            Route::post('/databases/{database}/import', [DatabaseTransferController::class, 'import'])->name('databases.import');
        });

        // Database tool (E-079): every page runs db-query on the server.
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/databases/{database}/tables', [DatabaseToolController::class, 'tables'])->name('databases.tables');
            Route::get('/databases/{database}/table', [DatabaseToolController::class, 'table'])->name('databases.table');
            Route::get('/databases/{database}/sql', [DatabaseToolController::class, 'console'])->name('databases.sql');
            Route::post('/databases/{database}/sql', [DatabaseToolController::class, 'run'])->name('databases.run');
        });
        // These start scripts on a server, so they are limited like the test script.
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
            Route::post('/sites/{site}/retry', [SiteController::class, 'retry'])->name('sites.retry');
            Route::post('/sites/{site}/certificate', [SiteController::class, 'requestCertificate'])->name('sites.certificate');
            Route::post('/sites/{site}/www', [SiteController::class, 'updateWww'])->name('sites.www');
            Route::post('/sites/{site}/php', [SiteController::class, 'updatePhp'])->name('sites.php');
            Route::post('/sites/{site}/php/settings', [SiteController::class, 'updatePhpSettings'])->name('sites.php.settings');
            Route::post('/sites/{site}/domain', [SiteController::class, 'updateDomain'])->name('sites.domain');
            Route::post('/sites/{site}/databases', [DatabaseController::class, 'store'])->name('databases.store');
            Route::post('/databases/{database}/retry', [DatabaseController::class, 'retry'])->name('databases.retry');
            Route::post('/databases/{database}/password', [DatabaseController::class, 'changePassword'])->name('databases.password');
            Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
            Route::delete('/sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
        });

        Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
        Route::get('/servers/{server}', [ServerController::class, 'show'])->name('servers.show');
        Route::get('/servers/{server}/load', [ServerController::class, 'load'])->name('servers.load');
        Route::get('/servers/{server}/updates', [ServerController::class, 'updates'])->name('servers.updates');
        Route::get('/servers/{server}/tasks', [ServerController::class, 'tasks'])->name('servers.tasks');
        // Backups of the websites of a server (E-096).
        Route::get('/servers/{server}/backups', [ServerBackupController::class, 'index'])->name('servers.backups');
        Route::put('/servers/{server}/backups', [ServerBackupController::class, 'update'])->middleware('throttle:10,1')->name('servers.backups.update');
        Route::get('/servers/{server}/backups/panel/{snapshot}', [ServerBackupController::class, 'panelSnapshot'])->where('snapshot', '[0-9a-f]{8}')->middleware('throttle:30,1')->name('servers.backups.panel.show');
        Route::post('/servers/{server}/backups/panel', [ServerBackupController::class, 'backupPanel'])->middleware('throttle:10,1')->name('servers.backups.panel');
        Route::post('/servers/{server}/backups/all', [ServerBackupController::class, 'backupAll'])->middleware('throttle:10,1')->name('servers.backups.all');
        Route::post('/servers/{server}/backups/rebuild', [ServerBackupController::class, 'rebuild'])->middleware('throttle:10,1')->name('servers.backups.rebuild');

        // Hetzner snapshots (E-095).
        Route::get('/servers/{server}/snapshots', [ServerSnapshotController::class, 'index'])->name('servers.snapshots');
        Route::middleware('throttle:20,1')->group(function () {
            Route::put('/servers/{server}/snapshots/schedule', [ServerSnapshotController::class, 'updateSchedule'])->name('servers.snapshots.schedule');
            Route::post('/servers/{server}/snapshots', [ServerSnapshotController::class, 'store'])->name('servers.snapshots.store');
            Route::delete('/servers/{server}/snapshots/{image}', [ServerSnapshotController::class, 'destroy'])->whereNumber('image')->name('servers.snapshots.destroy');
        });
        Route::get('/servers/{server}/logs', [LogController::class, 'server'])->middleware('throttle:30,1')->name('servers.logs');
        // SSH password login of the server (E-155).
        Route::get('/servers/{server}/ssh', [ServerSshController::class, 'show'])->middleware('throttle:30,1')->name('servers.ssh');
        Route::post('/servers/{server}/ssh', [ServerSshController::class, 'update'])->middleware('throttle:10,1')->name('servers.ssh.update');
        // DNS and reverse DNS of the server (E-153).
        Route::get('/servers/{server}/dns', [ServerDnsController::class, 'show'])->middleware('throttle:30,1')->name('servers.dns');
        Route::post('/servers/{server}/dns/check', [ServerDnsController::class, 'check'])->middleware('throttle:10,1')->name('servers.dns.check');
        Route::post('/servers/{server}/dns/records', [ServerDnsController::class, 'records'])->middleware('throttle:10,1')->name('servers.dns.records');
        Route::post('/servers/{server}/dns/ptr', [ServerDnsController::class, 'ptr'])->middleware('throttle:10,1')->name('servers.dns.ptr');
        // Firewall and fail2ban (E-090).
        Route::get('/servers/{server}/firewall', [ServerFirewallController::class, 'index'])->name('servers.firewall');
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/servers/{server}/firewall/rules', [ServerFirewallController::class, 'storeRule'])->name('servers.firewall.rules.store');
            Route::delete('/servers/{server}/firewall/rules/{firewallRule}', [ServerFirewallController::class, 'destroyRule'])->name('servers.firewall.rules.destroy');
            Route::post('/servers/{server}/firewall/ignores', [ServerFirewallController::class, 'storeIgnore'])->name('servers.firewall.ignores.store');
            Route::delete('/servers/{server}/firewall/ignores/{fail2banIgnore}', [ServerFirewallController::class, 'destroyIgnore'])->name('servers.firewall.ignores.destroy');
            Route::post('/servers/{server}/firewall/unban', [ServerFirewallController::class, 'unban'])->name('servers.firewall.unban');
            Route::patch('/servers/{server}/firewall/blocklists/{blocklist}', [ServerFirewallController::class, 'updateBlocklist'])->name('servers.firewall.blocklists.update');
            Route::delete('/servers/{server}/firewall/blocklists/{blocklist}', [ServerFirewallController::class, 'destroyBlocklist'])->name('servers.firewall.blocklists.destroy');
        });
        // Blocklists are fetched while the administrator waits (E-170).
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/servers/{server}/firewall/blocklists', [ServerFirewallController::class, 'storeBlocklist'])->name('servers.firewall.blocklists.store');
            Route::post('/servers/{server}/firewall/blocklists/{blocklist}/refresh', [ServerFirewallController::class, 'refreshBlocklist'])->name('servers.firewall.blocklists.refresh');
        });
        // Each click starts a script on the server, so clicks are limited.
        Route::post('/servers/{server}/test', [ServerController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('servers.test');
        Route::post('/servers/{server}/updates/check', [ServerController::class, 'checkUpdates'])
            ->middleware('throttle:10,1')
            ->name('servers.updates.check');
        Route::post('/servers/{server}/updates/release', [ServerController::class, 'checkRelease'])
            ->middleware('throttle:10,1')
            ->name('servers.updates.release');
        Route::post('/servers/{server}/updates/install', [ServerController::class, 'installUpdates'])
            ->middleware('throttle:5,1')
            ->name('servers.updates.install');
        Route::post('/servers/{server}/updates/panel', [ServerController::class, 'updatePanel'])
            ->middleware('throttle:5,1')
            ->name('servers.updates.panel');
        Route::post('/servers/{server}/updates/panel-auto', [ServerController::class, 'panelAutoUpdate'])
            ->middleware('throttle:10,1')
            ->name('servers.updates.panel_auto');
        Route::post('/servers/{server}/reboot', [ServerController::class, 'reboot'])
            ->middleware('throttle:5,1')
            ->name('servers.reboot');

        // Logs of the panel itself under System (E-087).
        Route::get('/system/logs', [LogController::class, 'panel'])->middleware('throttle:30,1')->name('logs.panel');
        // Workers and scheduler of the panel (E-133).
        Route::get('/system/workers', [PanelWorkerController::class, 'index'])->name('workers.index');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/system/workers/restart', [PanelWorkerController::class, 'restart'])->name('workers.restart');
            Route::post('/system/workers/pause', [PanelWorkerController::class, 'pause'])->name('workers.pause');
            Route::post('/system/workers/resume', [PanelWorkerController::class, 'resume'])->name('workers.resume');
        });
        Route::get('/tasks/{task}', [ServerTaskController::class, 'show'])->name('tasks.show');
    });
});
