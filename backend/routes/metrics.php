<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MetricsController;

/*
|--------------------------------------------------------------------------
| Metrics Routes
|--------------------------------------------------------------------------
|
| Prometheus metrics endpoint - protected by token/IP authentication
|
*/

Route::middleware(['throttle:60,1'])->group(function () {
    // Prometheus format (default)
    Route::get('/metrics', [MetricsController::class, 'prometheus'])
        ->name('metrics.prometheus');

    // JSON format (for debugging/internal dashboards)
    Route::get('/metrics/json', [MetricsController::class, 'json'])
        ->name('metrics.json');
});
