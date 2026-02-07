<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RollbackController;

// Rollback API Routes
Route::prefix('rollback')->middleware([
    'auth:sanctum', 
    'admin', 
    'validate.multi.tenant.restore'
])->group(function () {
    
    // Execute rollback
    Route::post('/execute', [RollbackController::class, 'executeRollback'])
        ->name('rollback.execute');
    
    // Get rollback history
    Route::get('/history', [RollbackController::class, 'getHistory'])
        ->name('rollback.history');
    
    // Get available backups
    Route::get('/backups', [RollbackController::class, 'getAvailableBackups'])
        ->name('rollback.backups');
});
