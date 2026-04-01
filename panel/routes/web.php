<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\EmailController;
use App\Http\Controllers\Admin\NodeController;
use App\Http\Controllers\Admin\NodeStatusController;
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

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
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

        // Domains
        Route::resource('domains', DomainController::class)->except(['edit', 'update']);
        Route::post('domains/{domain}/ssl', [DomainController::class, 'issueSSL'])->name('domains.ssl');

        // Email management
        Route::get('domains/{domain}/email', [EmailController::class, 'domainIndex'])->name('email.domain');
        Route::post('domains/{domain}/email/enable', [EmailController::class, 'enableDomain'])->name('email.enable');
        Route::post('domains/{domain}/email/mailboxes', [EmailController::class, 'createMailbox'])->name('email.mailbox.store');
        Route::delete('email/mailboxes/{mailbox}', [EmailController::class, 'deleteMailbox'])->name('email.mailbox.destroy');
        Route::put('email/mailboxes/{mailbox}/password', [EmailController::class, 'changePassword'])->name('email.mailbox.password');
        Route::post('domains/{domain}/email/forwarders', [EmailController::class, 'createForwarder'])->name('email.forwarder.store');
        Route::delete('email/forwarders/{forwarder}', [EmailController::class, 'deleteForwarder'])->name('email.forwarder.destroy');
    });
});
