<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\MigrationController;
use Illuminate\Support\Facades\Route;

/*
 * Strata Hosting Panel — Billing / Provisioning REST API  (v1)
 *
 * Authentication: Bearer token (Laravel Sanctum personal access token)
 * created by an admin via Admin → API Tokens.
 *
 * All responses are JSON.
 */

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    // ── Accounts ──────────────────────────────────────────────────────────────
    Route::get('accounts', [AccountController::class, 'index'])
        ->middleware('ability:accounts:usage')
        ->name('api.v1.accounts.index');

    Route::post('accounts', [AccountController::class, 'store'])
        ->middleware('ability:accounts:create')
        ->name('api.v1.accounts.store');

    Route::get('accounts/{account}', [AccountController::class, 'show'])
        ->middleware('ability:accounts:usage')
        ->name('api.v1.accounts.show');

    Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])
        ->middleware('ability:accounts:suspend')
        ->name('api.v1.accounts.suspend');

    Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])
        ->middleware('ability:accounts:unsuspend')
        ->name('api.v1.accounts.unsuspend');

    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])
        ->middleware('ability:accounts:terminate')
        ->name('api.v1.accounts.destroy');

    Route::get('accounts/{account}/usage', [AccountController::class, 'usage'])
        ->middleware('ability:accounts:usage')
        ->name('api.v1.accounts.usage');

    Route::get('migrations', [MigrationController::class, 'index'])
        ->middleware('ability:migrations:read')
        ->name('api.v1.migrations.index');

    Route::post('migrations', [MigrationController::class, 'store'])
        ->middleware('ability:migrations:write')
        ->name('api.v1.migrations.store');

    Route::get('migrations/{migration}', [MigrationController::class, 'show'])
        ->middleware('ability:migrations:read')
        ->name('api.v1.migrations.show');

    Route::post('migrations/{migration}/transfer', [MigrationController::class, 'transfer'])
        ->middleware('ability:migrations:write')
        ->name('api.v1.migrations.transfer');

    Route::post('migrations/{migration}/restore', [MigrationController::class, 'restore'])
        ->middleware('ability:migrations:write')
        ->name('api.v1.migrations.restore');

    Route::post('migrations/{migration}/cutover', [MigrationController::class, 'cutover'])
        ->middleware('ability:migrations:write')
        ->name('api.v1.migrations.cutover');

    Route::post('migrations/{migration}/cleanup-source', [MigrationController::class, 'cleanupSource'])
        ->middleware('ability:migrations:write')
        ->name('api.v1.migrations.cleanup-source');

    // Catalog discovery for billing/provisioning integrations.
    Route::get('packages', [CatalogController::class, 'packages'])
        ->middleware('ability:catalog:read')
        ->name('api.v1.packages.index');

    Route::get('feature-lists', [CatalogController::class, 'featureLists'])
        ->middleware('ability:catalog:read')
        ->name('api.v1.feature-lists.index');
});
