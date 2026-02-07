<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;

// Health check routes (no authentication required for load balancer)
Route::get('/health', [HealthCheckController::class, 'health']);
Route::get('/health/detailed', [HealthCheckController::class, 'detailedHealth']);
Route::get('/health/load-balancer', [HealthCheckController::class, 'loadBalancerHealth']);
