<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\CatalogController;
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

    // Catalog discovery for billing/provisioning integrations.
    Route::get('packages', [CatalogController::class, 'packages'])
        ->middleware('ability:catalog:read')
        ->name('api.v1.packages.index');

    Route::get('feature-lists', [CatalogController::class, 'featureLists'])
        ->middleware('ability:catalog:read')
        ->name('api.v1.feature-lists.index');
});
