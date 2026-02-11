<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SuperAdmin\SchoolController;

/*
|--------------------------------------------------------------------------
| Super Admin API Routes
|--------------------------------------------------------------------------
|
| Routes for Super Admin role
| All routes require auth:sanctum and super_admin role
|
| SECURITY MIDDLEWARE STACK:
| 1. auth:sanctum - Authentication
| 2. role:super_admin - Role-based access
| 3. log.super.admin - Audit logging for compliance
|
*/

Route::prefix('super-admin')
    ->middleware(['auth:sanctum', 'role:super_admin', 'log.super.admin'])
    ->group(function () {
        
        // School Management
        Route::prefix('schools')->group(function () {
            Route::get('/', [SchoolController::class, 'index']);
            Route::post('/', [SchoolController::class, 'store']);
            Route::get('/{id}', [SchoolController::class, 'show']);
            Route::put('/{id}', [SchoolController::class, 'update']);
            Route::delete('/{id}', [SchoolController::class, 'destroy']);
            
            // Activation
            Route::post('/{id}/activate', [SchoolController::class, 'activate']);
            Route::post('/{id}/deactivate', [SchoolController::class, 'deactivate']);
            
            // Impersonate (CRITICAL: requires additional logging)
            Route::post('/{id}/impersonate', [SchoolController::class, 'impersonate']);
            
            // Usage Stats
            Route::get('/{id}/usage', [SchoolController::class, 'usage']);
        });
        
    });
