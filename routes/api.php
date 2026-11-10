<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\LogoutController;
use App\Http\Controllers\Api\V1\StatementController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Middleware\EnsureIdempotency;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/token', AuthTokenController::class)->middleware('throttle:login');

    // Signed, short-lived link (no bearer token needed so a plain <a href> works).
    Route::get('/wallet/statements/{statement}/download', [StatementController::class, 'download'])
        ->middleware('signed')
        ->name('statements.download');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', LogoutController::class);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::post('/wallet/deposit', [WalletController::class, 'deposit']);

        Route::post('/wallet/transfer', [WalletController::class, 'transfer'])
            ->middleware(['throttle:transfers', EnsureIdempotency::class]);

        Route::post('/wallet/statements', [StatementController::class, 'store']);
        Route::get('/wallet/statements/{statement}', [StatementController::class, 'show'])
            ->name('statements.show');
    });
});