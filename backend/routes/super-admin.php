<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SuperAdmin\DashboardController;
use App\Http\Controllers\SuperAdmin\SchoolController;
use App\Http\Controllers\SuperAdmin\UserController;
use App\Http\Controllers\SuperAdmin\MonitoringController;
use App\Http\Controllers\SuperAdmin\SecurityController;
use App\Http\Controllers\SuperAdmin\BillingController;
use App\Http\Controllers\SuperAdmin\ReportController;
use App\Http\Controllers\SuperAdmin\SettingController;

/*
|--------------------------------------------------------------------------
| Super Admin Routes
|--------------------------------------------------------------------------
|
| Routes untuk Super Admin Dashboard
| Middleware: auth, role:super-admin
|
*/

Route::middleware(['auth', 'role:super-admin'])->prefix('super-admin')->name('super-admin.')->group(function () {
    
    // Dashboard Routes
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/', [DashboardController::class, 'overview'])->name('overview');
        Route::get('/school-stats', [DashboardController::class, 'schoolStats'])->name('school-stats');
        Route::get('/activity', [DashboardController::class, 'activity'])->name('activity');
    });
    
    // School Management Routes
    Route::prefix('schools')->name('schools.')->group(function () {
        Route::get('/', [SchoolController::class, 'list'])->name('list');
        Route::get('/activation', [SchoolController::class, 'activation'])->name('activation');
        Route::get('/packages', [SchoolController::class, 'packages'])->name('packages');
        
        // CRUD Operations
        Route::post('/', [SchoolController::class, 'store'])->name('store');
        Route::get('/{school}/edit', [SchoolController::class, 'edit'])->name('edit');
        Route::put('/{school}', [SchoolController::class, 'update'])->name('update');
        Route::delete('/{school}', [SchoolController::class, 'destroy'])->name('destroy');
        
        // Activation/Deactivation
        Route::post('/{school}/activate', [SchoolController::class, 'activate'])->name('activate');
        Route::post('/{school}/deactivate', [SchoolController::class, 'deactivate'])->name('deactivate');
    });
    
    // User Management Routes
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/superadmin', [UserController::class, 'superadmin'])->name('superadmin');
        Route::get('/support', [UserController::class, 'support'])->name('support');
        
        // CRUD Operations
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
    });
    
    // System Monitoring Routes
    Route::prefix('monitoring')->name('monitoring.')->group(function () {
        Route::get('/health', [MonitoringController::class, 'health'])->name('health');
        Route::get('/logs', [MonitoringController::class, 'logs'])->name('logs');
        Route::get('/queue', [MonitoringController::class, 'queue'])->name('queue');
        
        // API Endpoints for real-time data
        Route::get('/api/health-metrics', [MonitoringController::class, 'healthMetrics'])->name('api.health-metrics');
        Route::get('/api/server-status', [MonitoringController::class, 'serverStatus'])->name('api.server-status');
    });
    
    // Security Monitoring Routes
    Route::prefix('security')->name('security.')->group(function () {
        Route::get('/events', [SecurityController::class, 'events'])->name('events');
        Route::get('/suspicious', [SecurityController::class, 'suspicious'])->name('suspicious');
        Route::get('/api-abuse', [SecurityController::class, 'apiAbuse'])->name('api-abuse');
        
        // Actions
        Route::post('/events/{event}/resolve', [SecurityController::class, 'resolveEvent'])->name('events.resolve');
        Route::post('/ip/{ip}/block', [SecurityController::class, 'blockIp'])->name('ip.block');
    });
    
    // Billing & Subscription Routes
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/packages', [BillingController::class, 'packages'])->name('packages');
        Route::get('/invoices', [BillingController::class, 'invoices'])->name('invoices');
        Route::get('/history', [BillingController::class, 'history'])->name('history');
        
        // Package Management
        Route::post('/packages', [BillingController::class, 'storePackage'])->name('packages.store');
        Route::put('/packages/{package}', [BillingController::class, 'updatePackage'])->name('packages.update');
        
        // Invoice Actions
        Route::post('/invoices/{invoice}/send', [BillingController::class, 'sendInvoice'])->name('invoices.send');
        Route::post('/invoices/{invoice}/mark-paid', [BillingController::class, 'markAsPaid'])->name('invoices.mark-paid');
    });
    
    // Global Reports Routes
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/usage', [ReportController::class, 'usage'])->name('usage');
        Route::get('/attendance', [ReportController::class, 'attendance'])->name('attendance');
        
        // Export
        Route::post('/usage/export', [ReportController::class, 'exportUsage'])->name('usage.export');
        Route::post('/attendance/export', [ReportController::class, 'exportAttendance'])->name('attendance.export');
    });
    
    // System Settings Routes
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/flags', [SettingController::class, 'flags'])->name('flags');
        Route::get('/maintenance', [SettingController::class, 'maintenance'])->name('maintenance');
        
        // Feature Flags
        Route::post('/flags/{flag}/toggle', [SettingController::class, 'toggleFlag'])->name('flags.toggle');
        
        // Maintenance Mode
        Route::post('/maintenance/enable', [SettingController::class, 'enableMaintenance'])->name('maintenance.enable');
        Route::post('/maintenance/disable', [SettingController::class, 'disableMaintenance'])->name('maintenance.disable');
        
        // Cache Management
        Route::post('/cache/clear', [SettingController::class, 'clearCache'])->name('cache.clear');
        Route::post('/cache/flush', [SettingController::class, 'flushCache'])->name('cache.flush');
        
        // Account Settings
        Route::get('/account', [SettingController::class, 'account'])->name('account');
        Route::put('/account', [SettingController::class, 'updateAccount'])->name('account.update');
    });
    
    // Profile Routes
    Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
    Route::put('/profile', [DashboardController::class, 'updateProfile'])->name('profile.update');
});
