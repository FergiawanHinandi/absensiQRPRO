<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\NavigationController;

Route::prefix('auth')->group(function () {
    // Login with strict rate limiting (5 attempts per minute per IP)
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        
        // Server-driven navigation and permissions (no client-side role checking)
        Route::get('/navigation', [NavigationController::class, 'getNavigation']);
        Route::get('/permissions', [NavigationController::class, 'getPermissions']);
    });
});
