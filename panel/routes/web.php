<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliverabilityController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\DnsController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\EmailController;
use App\Http\Controllers\Admin\FeatureListController;
use App\Http\Controllers\Admin\FtpController;
use App\Http\Controllers\Admin\HostingPackageController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController as AdminBackupController;
use App\Http\Controllers\Admin\LicenseSyncController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\NodeStatusController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\BackupScheduleController;
use App\Http\Controllers\Admin\RemoteBackupDestinationController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SpamController;
use App\Http\Controllers\Admin\StandaloneDnsController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\AdminWebsiteController;
use App\Http\Controllers\Admin\ShellController;
use App\Http\Controllers\User\AutoresponderController;
use App\Http\Controllers\User\AppInstallerController;
use App\Http\Controllers\User\SshKeyController;
use App\Http\Controllers\Reseller;
use App\Http\Controllers\User;
use App\Http\Controllers\WebmailController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Auth
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Two-factor challenge (auth but not yet 2FA-verified)
Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store']);
});

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // User portal (also accessible by admin for testing/support)
    Route::middleware('role:user|admin')->prefix('my')->name('my.')->group(function () {
        Route::get('/', [User\DashboardController::class, 'index'])->name('dashboard');

        // Domains
        Route::get('domains', [User\DomainController::class, 'index'])->name('domains.index');
        Route::get('domains/create', [User\DomainController::class, 'create'])->name('domains.create');
        Route::post('domains', [User\DomainController::class, 'store'])->name('domains.store');
        Route::get('domains/{domain}', [User\DomainController::class, 'show'])->name('domains.show');
        Route::post('domains/{domain}/ssl', [User\DomainController::class, 'issueSSL'])->name('domains.ssl');
        Route::post('domains/{domain}/ssl/custom', [User\DomainController::class, 'uploadCert'])->name('domains.ssl.custom');
        Route::put('domains/{domain}/php', [User\DomainController::class, 'changePhp'])->name('domains.php');
        Route::put('domains/{domain}/directives', [User\DomainController::class, 'updateDirectives'])->name('domains.directives');
        Route::post('domains/{domain}/redirects', [User\DomainController::class, 'storeRedirect'])->name('domains.redirects.store');
        Route::delete('domains/{domain}/redirects/{index}', [User\DomainController::class, 'destroyRedirect'])->name('domains.redirects.destroy');

        // Email (per-domain)
        Route::get('domains/{domain}/email', [User\EmailController::class, 'index'])->name('email.domain');
        Route::post('domains/{domain}/email/mailboxes', [User\EmailController::class, 'createMailbox'])->name('email.mailbox.store');
        Route::delete('email/mailboxes/{mailbox}', [User\EmailController::class, 'deleteMailbox'])->name('email.mailbox.destroy');
        Route::put('email/mailboxes/{mailbox}/password', [User\EmailController::class, 'changePassword'])->name('email.mailbox.password');
        Route::post('domains/{domain}/email/forwarders', [User\EmailController::class, 'createForwarder'])->name('email.forwarder.store');
        Route::delete('email/forwarders/{forwarder}', [User\EmailController::class, 'deleteForwarder'])->name('email.forwarder.destroy');

        // Autoresponders
        Route::get('domains/{domain}/email/autoresponders', [AutoresponderController::class, 'index'])->name('email.autoresponders');
        Route::post('email/mailboxes/{emailAccount}/autoresponder', [AutoresponderController::class, 'store'])->name('email.autoresponder.store');
        Route::delete('email/mailboxes/{emailAccount}/autoresponder', [AutoresponderController::class, 'destroy'])->name('email.autoresponder.destroy');

        // Databases
        Route::get('databases', [User\DatabaseController::class, 'index'])->name('databases.index');
        Route::post('databases', [User\DatabaseController::class, 'store'])->name('databases.store');
        Route::delete('databases/{database}', [User\DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::put('databases/{database}/password', [User\DatabaseController::class, 'changePassword'])->name('databases.password');
        Route::post('databases/{database}/grant', [User\DatabaseController::class, 'grantUser'])->name('databases.grant');
        Route::delete('databases/{database}/revoke', [User\DatabaseController::class, 'revokeUser'])->name('databases.revoke');

        // FTP
        Route::get('ftp', [User\FtpController::class, 'index'])->name('ftp.index');
        Route::post('ftp', [User\FtpController::class, 'store'])->name('ftp.store');
        Route::delete('ftp/{ftpAccount}', [User\FtpController::class, 'destroy'])->name('ftp.destroy');
        Route::put('ftp/{ftpAccount}/password', [User\FtpController::class, 'changePassword'])->name('ftp.password');

        // DNS
        Route::get('dns', [User\DnsController::class, 'index'])->name('dns.index');
        Route::get('domains/{domain}/dns', [User\DnsController::class, 'show'])->name('dns.show');
        Route::get('domains/{domain}/dns/export', [User\DnsController::class, 'exportZone'])->name('dns.export');
        Route::post('domains/{domain}/dns/import', [User\DnsController::class, 'importZone'])->name('dns.import');
        Route::post('dns/zones/{zone}/records', [User\DnsController::class, 'storeRecord'])->name('dns.records.store');
        Route::delete('dns/records/{record}', [User\DnsController::class, 'destroyRecord'])->name('dns.records.destroy');

        // File manager
        Route::get('files', [User\FileManagerController::class, 'index'])->name('files.index');
        Route::get('files/list', [User\FileManagerController::class, 'list'])->name('files.list');
        Route::get('files/read', [User\FileManagerController::class, 'read'])->name('files.read');
        Route::get('files/download', [User\FileManagerController::class, 'download'])->name('files.download');
        Route::post('files/write', [User\FileManagerController::class, 'write'])->name('files.write');
        Route::post('files/mkdir', [User\FileManagerController::class, 'mkdir'])->name('files.mkdir');
        Route::post('files/rename', [User\FileManagerController::class, 'rename'])->name('files.rename');
        Route::delete('files/delete', [User\FileManagerController::class, 'delete'])->name('files.delete');
        Route::post('files/chmod', [User\FileManagerController::class, 'chmod'])->name('files.chmod');
        Route::post('files/compress', [User\FileManagerController::class, 'compress'])->name('files.compress');
        Route::post('files/extract', [User\FileManagerController::class, 'extract'])->name('files.extract');
        Route::post('files/upload', [User\FileManagerController::class, 'upload'])->name('files.upload');

        // Email deliverability troubleshooter
        Route::get('deliverability', [DeliverabilityController::class, 'userIndex'])->name('deliverability.index');
        Route::post('deliverability/check', [DeliverabilityController::class, 'check'])->name('deliverability.check');

        // Backups
        Route::get('backups', [User\BackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [User\BackupController::class, 'store'])->name('backups.store');
        Route::delete('backups/{backup}', [User\BackupController::class, 'destroy'])->name('backups.destroy');
        Route::get('backups/{backup}/download', [User\BackupController::class, 'download'])->name('backups.download');
        Route::post('backups/{backup}/restore', [User\BackupController::class, 'restore'])->name('backups.restore');

        // PHP settings
        Route::get('php', [User\PhpController::class, 'index'])->name('php.index');
        Route::put('php', [User\PhpController::class, 'update'])->name('php.update');

        // SSH Keys
        Route::get('security/ssh-keys', [SshKeyController::class, 'index'])->name('ssh-keys.index');
        Route::post('security/ssh-keys', [SshKeyController::class, 'store'])->name('ssh-keys.store');
        Route::delete('security/ssh-keys/{sshKey}', [SshKeyController::class, 'destroy'])->name('ssh-keys.destroy');

        // App Installer
        Route::prefix('apps')->name('apps.')->group(function () {
            Route::get('/', [AppInstallerController::class, 'catalog'])->name('catalog');
            Route::get('/installed', [AppInstallerController::class, 'myApps'])->name('installed');
            Route::post('/install', [AppInstallerController::class, 'install'])->name('install');
            Route::post('/{installation}/update', [AppInstallerController::class, 'update'])->name('update');
            Route::patch('/{installation}/auto-update', [AppInstallerController::class, 'toggleAutoUpdate'])->name('auto-update');
            Route::delete('/{installation}', [AppInstallerController::class, 'destroy'])->name('destroy');
        });
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        // License sync
        Route::post('license/sync', LicenseSyncController::class)->name('license.sync');

        // Nodes
        Route::resource('nodes', NodeController::class);
        Route::get('nodes/{node}/status', [NodeStatusController::class, 'show'])->name('nodes.status');
        Route::get('nodes/{node}/shell', [ShellController::class, 'show'])->name('nodes.shell');
        Route::get('nodes/{node}/api/info', [NodeStatusController::class, 'info'])->name('nodes.api.info');
        Route::get('nodes/{node}/api/logs/{service}', [NodeStatusController::class, 'logs'])->name('nodes.api.logs');
        Route::post('nodes/{node}/api/services/{service}/action', [NodeStatusController::class, 'serviceAction'])->name('nodes.api.service-action');

        // Accounts
        Route::resource('accounts', AccountController::class)->except(['edit', 'update']);
        Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
        Route::resource('packages', HostingPackageController::class)->except(['show']);
        Route::resource('feature-lists', FeatureListController::class)->except(['show']);

        // Databases (per account)
        Route::get('accounts/{account}/databases', [DatabaseController::class, 'index'])->name('accounts.databases');
        Route::post('accounts/{account}/databases', [DatabaseController::class, 'store'])->name('accounts.databases.store');
        Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::put('databases/{database}/password', [DatabaseController::class, 'changePassword'])->name('databases.password');
        Route::post('accounts/{account}/databases/grant', [DatabaseController::class, 'grantUser'])->name('databases.grant');
        Route::delete('accounts/{account}/databases/revoke', [DatabaseController::class, 'revokeUser'])->name('databases.revoke');

        // FTP (per account)
        Route::get('accounts/{account}/ftp', [FtpController::class, 'index'])->name('accounts.ftp');
        Route::post('accounts/{account}/ftp', [FtpController::class, 'store'])->name('accounts.ftp.store');
        Route::delete('ftp/{ftpAccount}', [FtpController::class, 'destroy'])->name('ftp.destroy');
        Route::put('ftp/{ftpAccount}/password', [FtpController::class, 'changePassword'])->name('ftp.password');

        // Domains
        Route::resource('domains', DomainController::class)->except(['edit', 'update']);
        Route::post('domains/{domain}/ssl', [DomainController::class, 'issueSSL'])->name('domains.ssl');

        // DNS
        Route::get('dns', [DnsController::class, 'index'])->name('dns.index');
        Route::get('domains/{domain}/dns', [DnsController::class, 'show'])->name('dns.show');
        Route::get('domains/{domain}/dns/export', [DnsController::class, 'export'])->name('dns.export');
        Route::post('domains/{domain}/dns/import', [DnsController::class, 'import'])->name('dns.import');
        Route::post('domains/{domain}/dns/provision', [DnsController::class, 'provision'])->name('dns.provision');
        Route::post('dns/zones/{zone}/records', [DnsController::class, 'storeRecord'])->name('dns.records.store');
        Route::delete('dns/records/{record}', [DnsController::class, 'destroyRecord'])->name('dns.records.destroy');

        // Email management
        Route::get('domains/{domain}/email', [EmailController::class, 'domainIndex'])->name('email.domain');
        Route::post('domains/{domain}/email/enable', [EmailController::class, 'enableDomain'])->name('email.enable');
        Route::post('domains/{domain}/email/mailboxes', [EmailController::class, 'createMailbox'])->name('email.mailbox.store');
        Route::delete('email/mailboxes/{mailbox}', [EmailController::class, 'deleteMailbox'])->name('email.mailbox.destroy');
        Route::put('email/mailboxes/{mailbox}/password', [EmailController::class, 'changePassword'])->name('email.mailbox.password');
        Route::post('domains/{domain}/email/forwarders', [EmailController::class, 'createForwarder'])->name('email.forwarder.store');
        Route::delete('email/forwarders/{forwarder}', [EmailController::class, 'deleteForwarder'])->name('email.forwarder.destroy');

        // Resellers
        Route::resource('resellers', ResellerController::class)->except(['edit']);

        // Audit log
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');

        // Email deliverability troubleshooter
        Route::get('deliverability', [DeliverabilityController::class, 'adminIndex'])->name('deliverability.index');
        Route::post('deliverability/check', [DeliverabilityController::class, 'check'])->name('deliverability.check');

        // Backups
        Route::get('backups', [AdminBackupController::class, 'index'])->name('backups.index');
        Route::post('backups', [AdminBackupController::class, 'store'])->name('backups.store');
        Route::delete('backups/{backup}', [AdminBackupController::class, 'destroy'])->name('backups.destroy');
        Route::post('backups/{backup}/restore', [AdminBackupController::class, 'restore'])->name('backups.restore');

        // Backup schedules
        Route::get('backups/schedules', [BackupScheduleController::class, 'index'])->name('backups.schedules');
        Route::put('backups/schedules/{account}', [BackupScheduleController::class, 'update'])->name('backups.schedules.update');

        // Security (fail2ban + firewall)
        Route::get('security', [SecurityController::class, 'index'])->name('security.index');
        Route::get('security/fail2ban', [SecurityController::class, 'fail2banStatus'])->name('security.fail2ban');
        Route::post('security/unban', [SecurityController::class, 'unban'])->name('security.unban');
        Route::get('security/firewall', [SecurityController::class, 'firewallIndex'])->name('security.firewall');
        Route::get('security/firewall/rules', [SecurityController::class, 'firewallRules'])->name('security.firewall.rules');
        Route::post('security/firewall/rules', [SecurityController::class, 'firewallAdd'])->name('security.firewall.add');
        Route::delete('security/firewall/rules', [SecurityController::class, 'firewallDelete'])->name('security.firewall.delete');

        // OS updates
        Route::get('updates', [UpdateController::class, 'index'])->name('updates.index');
        Route::get('updates/available', [UpdateController::class, 'available'])->name('updates.available');
        Route::post('updates/apply', [UpdateController::class, 'apply'])->name('updates.apply');

        // API tokens (billing integration)
        Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
        Route::post('api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('api-tokens/{id}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

        // Remote backup destinations
        Route::get('backups/destinations', [RemoteBackupDestinationController::class, 'index'])->name('backups.destinations');
        Route::post('backups/destinations', [RemoteBackupDestinationController::class, 'store'])->name('backups.destinations.store');
        Route::delete('backups/destinations/{destination}', [RemoteBackupDestinationController::class, 'destroy'])->name('backups.destinations.destroy');
        Route::post('backups/destinations/{destination}/toggle', [RemoteBackupDestinationController::class, 'toggle'])->name('backups.destinations.toggle');

        // Spam filter (Rspamd)
        Route::get('security/spam', [SpamController::class, 'index'])->name('security.spam');
        Route::get('security/spam/stats', [SpamController::class, 'stats'])->name('security.spam.stats');

        // Admin's own website (apex domain hosted alongside the panel)
        Route::get('my-website', [AdminWebsiteController::class, 'index'])->name('my-website.index');
        Route::post('my-website', [AdminWebsiteController::class, 'provision'])->name('my-website.provision');
        Route::delete('my-website', [AdminWebsiteController::class, 'deprovision'])->name('my-website.deprovision');

        // Standalone / server DNS zones
        Route::get('dns/server', [StandaloneDnsController::class, 'index'])->name('dns.server.index');
        Route::post('dns/server', [StandaloneDnsController::class, 'store'])->name('dns.server.store');
        Route::get('dns/server/{zone}', [StandaloneDnsController::class, 'show'])->name('dns.server.show');
        Route::delete('dns/server/{zone}', [StandaloneDnsController::class, 'destroy'])->name('dns.server.destroy');
        Route::post('dns/server/{zone}/records', [StandaloneDnsController::class, 'storeRecord'])->name('dns.server.records.store');
        Route::delete('dns/server/records/{record}', [StandaloneDnsController::class, 'destroyRecord'])->name('dns.server.records.destroy');
    });

    // Profile / Security
    Route::get('profile/security', [ProfileController::class, 'show'])->name('profile.security');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::post('profile/two-factor/enable', [ProfileController::class, 'enableTwoFactor'])->name('profile.two-factor.enable');
    Route::post('profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm');
    Route::delete('profile/two-factor/disable', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');
    Route::delete('profile/two-factor/disable-unconfirmed', [ProfileController::class, 'cancelTwoFactorSetup'])->name('profile.two-factor.disable-unconfirmed');
    Route::post('profile/two-factor/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->name('profile.two-factor.recovery-codes');

    // Webmail SSO — accessible by admin, reseller, and end user
    Route::post('webmail/sso/{mailbox}', [WebmailController::class, 'sso'])->name('webmail.sso');

    // Reseller portal
    Route::middleware('role:reseller')->prefix('reseller')->name('reseller.')->group(function () {
        Route::get('/', [Reseller\DashboardController::class, 'index'])->name('dashboard');

        // Client account management
        Route::get('accounts', [Reseller\AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [Reseller\AccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [Reseller\AccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/{account}/suspend', [Reseller\AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [Reseller\AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
        Route::delete('accounts/{account}', [Reseller\AccountController::class, 'destroy'])->name('accounts.destroy');

        // Client detail + limit editing
        Route::get('clients/{account}', [Reseller\ClientController::class, 'show'])->name('clients.show');
        Route::put('clients/{account}', [Reseller\ClientController::class, 'update'])->name('clients.update');

        // White-label branding
        Route::get('branding', [Reseller\BrandingController::class, 'edit'])->name('branding');
        Route::put('branding', [Reseller\BrandingController::class, 'update'])->name('branding.update');
    });
});
