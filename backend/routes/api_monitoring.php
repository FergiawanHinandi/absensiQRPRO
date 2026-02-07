<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BackupMonitoringController;

// Backup Monitoring API Routes
Route::prefix('monitoring')->middleware(['auth:sanctum', 'admin'])->group(function () {
    
    // Dashboard data
    Route::get('/dashboard', [BackupMonitoringController::class, 'dashboard'])
        ->name('monitoring.dashboard');
    
    // Job list with filters
    Route::get('/jobs', [BackupMonitoringController::class, 'jobs'])
        ->name('monitoring.jobs');
    
    // Job details
    Route::get('/jobs/{jobId}', [BackupMonitoringController::class, 'jobDetails'])
        ->name('monitoring.job.details');
    
    // Real-time job status
    Route::get('/jobs/{jobId}/status', [BackupMonitoringController::class, 'jobStatus'])
        ->name('monitoring.job.status');
    
    // Cancel running job
    Route::post('/jobs/{jobId}/cancel', [BackupMonitoringController::class, 'cancelJob'])
        ->name('monitoring.job.cancel');
    
    // Statistics
    Route::get('/statistics', [BackupMonitoringController::class, 'statistics'])
        ->name('monitoring.statistics');
    
    // Configuration
    Route::get('/configuration', [BackupMonitoringController::class, 'configuration'])
        ->name('monitoring.configuration');
});
