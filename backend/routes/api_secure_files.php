<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SecureFileUploadController;

// Secure File Upload API Routes
Route::prefix('files')->middleware(['auth:sanctum'])->group(function () {
    
    // Upload file securely
    // (Validation handled by SecureFileUploadRequest — MIME check, content scanning,
    //  EXIF analysis, PDF header/JS validation, size limit 2MB)
    Route::post('/upload', [SecureFileUploadController::class, 'upload'])
        ->name('files.upload');
    
    // Get user's uploaded files
    Route::get('/', [SecureFileUploadController::class, 'index'])
        ->name('files.index');
    
    // Get file metadata
    Route::get('/{filePath}', [SecureFileUploadController::class, 'show'])
        ->name('files.show')
        ->where('filePath', '.*');
    
    // Generate secure download URL
    Route::get('/{filePath}/download', [SecureFileUploadController::class, 'download'])
        ->name('files.download')
        ->where('filePath', '.*');
    
    // Validate file integrity
    Route::post('/{filePath}/validate', [SecureFileUploadController::class, 'validate'])
        ->name('files.validate')
        ->where('filePath', '.*');
    
    // Delete file
    Route::delete('/{filePath}', [SecureFileUploadController::class, 'destroy'])
        ->name('files.destroy')
        ->where('filePath', '.*');

    // Serve file content via signed URL (ownership re-checked on access)
    Route::get('/serve/{filePath}', [SecureFileUploadController::class, 'serve'])
        ->name('files.serve')
        ->where('filePath', '.*');
});
