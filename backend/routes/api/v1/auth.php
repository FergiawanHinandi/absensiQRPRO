<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\NavigationController;

Route::prefix('auth')->group(function () {
    // Login with strict rate limiting (5 attempts per minute per IP)
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Public registration (student/parent only, requires admin approval)
    Route::post('/register', [RegisterController::class, 'register'])
        ->middleware('throttle:5,1');

    // Password reset (public)
    Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware('throttle:3,1');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:3,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Token management
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/revoke-other-sessions', [AuthController::class, 'revokeOtherSessions']);

        // Server-driven navigation and permissions (no client-side role checking)
        Route::get('/navigation', [NavigationController::class, 'getNavigation']);
        Route::get('/permissions', [NavigationController::class, 'getPermissions']);
    });
});
