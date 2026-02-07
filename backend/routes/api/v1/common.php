<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\RegionController;
use App\Http\Controllers\Api\V1\CallbackController;

Route::get('/health', [HealthController::class, 'check']);

Route::prefix('region')->group(function () {
    Route::get('/provinces', [RegionController::class, 'provinces']);
    Route::get('/regencies/{provinceId}', [RegionController::class, 'regencies']);
    Route::get('/districts/{regencyId}', [RegionController::class, 'districts']);
    Route::get('/villages/{districtId}', [RegionController::class, 'villages']);
});

Route::post('/callback/xendit', [CallbackController::class, 'xendit']);
