<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\DnsController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\EmailController;
use App\Http\Controllers\Admin\FtpController;
use App\Http\Controllers\Admin\LicenseSyncController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\NodeStatusController;
use App\Http\Controllers\Admin\ResellerController;
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

    // User portal
    Route::middleware('role:user')->prefix('my')->name('my.')->group(function () {
        Route::get('/', [User\DashboardController::class, 'index'])->name('dashboard');

        // Domains
        Route::get('domains', [User\DomainController::class, 'index'])->name('domains.index');
        Route::get('domains/create', [User\DomainController::class, 'create'])->name('domains.create');
        Route::post('domains', [User\DomainController::class, 'store'])->name('domains.store');
        Route::get('domains/{domain}', [User\DomainController::class, 'show'])->name('domains.show');
        Route::post('domains/{domain}/ssl', [User\DomainController::class, 'issueSSL'])->name('domains.ssl');
        Route::put('domains/{domain}/php', [User\DomainController::class, 'changePhp'])->name('domains.php');

        // Email (per-domain)
        Route::get('domains/{domain}/email', [User\EmailController::class, 'index'])->name('email.domain');
        Route::post('domains/{domain}/email/mailboxes', [User\EmailController::class, 'createMailbox'])->name('email.mailbox.store');
        Route::delete('email/mailboxes/{mailbox}', [User\EmailController::class, 'deleteMailbox'])->name('email.mailbox.destroy');
        Route::put('email/mailboxes/{mailbox}/password', [User\EmailController::class, 'changePassword'])->name('email.mailbox.password');
        Route::post('domains/{domain}/email/forwarders', [User\EmailController::class, 'createForwarder'])->name('email.forwarder.store');
        Route::delete('email/forwarders/{forwarder}', [User\EmailController::class, 'deleteForwarder'])->name('email.forwarder.destroy');

        // Databases
        Route::get('databases', [User\DatabaseController::class, 'index'])->name('databases.index');
        Route::post('databases', [User\DatabaseController::class, 'store'])->name('databases.store');
        Route::delete('databases/{database}', [User\DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::put('databases/{database}/password', [User\DatabaseController::class, 'changePassword'])->name('databases.password');

        // FTP
        Route::get('ftp', [User\FtpController::class, 'index'])->name('ftp.index');
        Route::post('ftp', [User\FtpController::class, 'store'])->name('ftp.store');
        Route::delete('ftp/{ftpAccount}', [User\FtpController::class, 'destroy'])->name('ftp.destroy');
        Route::put('ftp/{ftpAccount}/password', [User\FtpController::class, 'changePassword'])->name('ftp.password');

        // DNS
        Route::get('domains/{domain}/dns', [User\DnsController::class, 'show'])->name('dns.show');
        Route::post('dns/zones/{zone}/records', [User\DnsController::class, 'storeRecord'])->name('dns.records.store');
        Route::delete('dns/records/{record}', [User\DnsController::class, 'destroyRecord'])->name('dns.records.destroy');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        // License sync
        Route::post('license/sync', LicenseSyncController::class)->name('license.sync');

        // Nodes
        Route::resource('nodes', NodeController::class);
        Route::get('nodes/{node}/status', [NodeStatusController::class, 'show'])->name('nodes.status');
        Route::get('nodes/{node}/api/info', [NodeStatusController::class, 'info'])->name('nodes.api.info');
        Route::get('nodes/{node}/api/logs/{service}', [NodeStatusController::class, 'logs'])->name('nodes.api.logs');
        Route::post('nodes/{node}/api/services/{service}/action', [NodeStatusController::class, 'serviceAction'])->name('nodes.api.service-action');

        // Accounts
        Route::resource('accounts', AccountController::class)->except(['edit', 'update']);
        Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');

        // Databases (per account)
        Route::get('accounts/{account}/databases', [DatabaseController::class, 'index'])->name('accounts.databases');
        Route::post('accounts/{account}/databases', [DatabaseController::class, 'store'])->name('accounts.databases.store');
        Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::put('databases/{database}/password', [DatabaseController::class, 'changePassword'])->name('databases.password');

        // FTP (per account)
        Route::get('accounts/{account}/ftp', [FtpController::class, 'index'])->name('accounts.ftp');
        Route::post('accounts/{account}/ftp', [FtpController::class, 'store'])->name('accounts.ftp.store');
        Route::delete('ftp/{ftpAccount}', [FtpController::class, 'destroy'])->name('ftp.destroy');
        Route::put('ftp/{ftpAccount}/password', [FtpController::class, 'changePassword'])->name('ftp.password');

        // Domains
        Route::resource('domains', DomainController::class)->except(['edit', 'update']);
        Route::post('domains/{domain}/ssl', [DomainController::class, 'issueSSL'])->name('domains.ssl');

        // DNS (per domain)
        Route::get('domains/{domain}/dns', [DnsController::class, 'show'])->name('dns.show');
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
    });
});
