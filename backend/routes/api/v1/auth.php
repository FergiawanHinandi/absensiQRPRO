<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes - API v1
|--------------------------------------------------------------------------
|
| Routes for user authentication including:
| - Login/Logout
| - Token refresh
| - Session management
|
*/

// ========================================
// PUBLIC AUTH ROUTES
// ========================================
Route::middleware(['rate.limit:global'])->group(function () {
    // Login with brute force protection
    Route::post('/login', [
        \App\Http\Controllers\Api\V1\AuthController::class,
        'login',
    ])->name('auth.login');

    // Test login (development only)
    if (app()->environment(['testing', 'local'])) {
        Route::post('/login-test', [
            \App\Http\Controllers\Api\V1\AuthController::class,
            'login',
        ])->name('auth.login-test');
    }
});

// ========================================
// PROTECTED AUTH ROUTES
// ========================================
Route::middleware(['auth:sanctum'])->group(function () {
    // Current user info
    Route::get('/me', [
        \App\Http\Controllers\Api\V1\AuthController::class,
        'me',
    ])->name('auth.me');

    // Logout
    Route::post('/logout', [
        \App\Http\Controllers\Api\V1\AuthController::class,
        'logout',
    ])->name('auth.logout');

    // Refresh token
    Route::post('/refresh', [
        \App\Http\Controllers\Api\V1\AuthController::class,
        'refresh',
    ])->name('auth.refresh');

    // ========================================
    // SESSION MANAGEMENT
    // ========================================
    Route::prefix('sessions')->group(function () {
        // List active sessions
        Route::get('/', [
            \App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
            'index',
        ])->name('auth.sessions.index');

        // Revoke specific session
        Route::delete('/{sessionId}', [
            \App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
            'destroy',
        ])->name('auth.sessions.destroy');

        // Revoke all other sessions
        Route::post('/revoke-others', [
            \App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
            'revokeOthers',
        ])->name('auth.sessions.revoke-others');
    });
});
