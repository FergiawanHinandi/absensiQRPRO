<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SuperAdmin\SchoolController;
use App\Http\Controllers\Api\V1\SuperAdmin\DashboardController;
use App\Http\Controllers\Api\V1\SuperAdmin\UserManagementController;
use App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController;
use App\Http\Controllers\Api\V1\SuperAdmin\PaymentController;
use App\Http\Controllers\Api\V1\SuperAdmin\BillingController;
use App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController;
use App\Http\Controllers\Api\V1\SuperAdmin\SecurityController;
use App\Http\Controllers\Api\V1\SuperAdmin\SystemController;
use App\Http\Controllers\Api\V1\SuperAdmin\GlobalReportController;
use App\Http\Controllers\Api\V1\SuperAdmin\AnnouncementController;

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
| 3. log.superadmin - Audit logging for compliance
|
*/

Route::prefix('super-admin')
    ->middleware(['auth:sanctum', 'role:super_admin', 'log.superadmin'])
    ->group(function () {
        
        // Dashboard
        Route::prefix('dashboard')->group(function () {
            Route::get('/stats', [DashboardController::class, 'index']);
        });
        
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
        
        // User Management
        Route::prefix('users')->group(function () {
            Route::get('/admins', [UserManagementController::class, 'getAdmins']);
            Route::post('/admins', [UserManagementController::class, 'store']);
            Route::post('/reset-access', [UserManagementController::class, 'resetAccess']);
            Route::patch('/{adminId}/status', [UserManagementController::class, 'toggleStatus']);
            Route::get('/activity-logs', [UserManagementController::class, 'activityLogs']);
        });
        
        // Billing — Subscription Packages
        Route::prefix('billing')->group(function () {
            Route::get('/packages', [SubscriptionPackageController::class, 'index']);
            Route::post('/packages', [SubscriptionPackageController::class, 'store']);
            Route::put('/packages/{id}', [SubscriptionPackageController::class, 'update']);
            Route::delete('/packages/{id}', [SubscriptionPackageController::class, 'destroy']);
            
            // Payments
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::post('/payments', [PaymentController::class, 'store']);
            Route::patch('/payments/{id}/status', [PaymentController::class, 'updateStatus']);
        });
        
        // Platform Configuration
        Route::prefix('config')->group(function () {
            Route::get('/templates', [PlatformConfigController::class, 'getScheduleTemplates']);
            Route::post('/templates', [PlatformConfigController::class, 'storeScheduleTemplate']);
            Route::delete('/templates/{id}', [PlatformConfigController::class, 'deleteScheduleTemplate']);
            
            Route::get('/features', [PlatformConfigController::class, 'getFeatureFlags']);
            Route::patch('/features/{id}', [PlatformConfigController::class, 'updateFeatureFlag']);
            
            Route::get('/academic-years', [PlatformConfigController::class, 'getAcademicYearsStats']);
            Route::post('/academic-years/deploy', [PlatformConfigController::class, 'deployAcademicYear']);
        });
        
        // Security
        Route::prefix('security')->group(function () {
            Route::get('/rate-limit', [SecurityController::class, 'rateLimitStats']);
            Route::get('/roles', [SecurityController::class, 'roles']);
            Route::get('/audit', [SecurityController::class, 'auditLogs']);
        });
        
        // System Management
        Route::prefix('system')->group(function () {
            Route::get('/maintenance/status', [SystemController::class, 'getMaintenanceStatus']);
            Route::post('/maintenance', [SystemController::class, 'toggleMaintenanceMode']);
            Route::get('/backup', [SystemController::class, 'backupDatabase']);
        });
        
        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/attendance-recap', [GlobalReportController::class, 'attendanceRecap']);
            Route::get('/platform-stats', [GlobalReportController::class, 'platformStats']);
            Route::get('/export-options', [GlobalReportController::class, 'getExportOptions']);
            Route::post('/export/trigger', [GlobalReportController::class, 'triggerExport']);
        });
        
        // Announcements (store available; index/update/delete need controller implementation)
        Route::prefix('announcements')->group(function () {
            Route::post('/', [AnnouncementController::class, 'store']);
        });
    });
