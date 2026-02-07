<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Principal\PrincipalDashboardController;

Route::middleware(['role:principal,vice_principal'])->prefix('principal')->group(function () {
    Route::get('/dashboard', [PrincipalDashboardController::class, 'index']);
    Route::get('/attendance-overview', [PrincipalDashboardController::class, 'attendanceOverview']);
    Route::get('/class-performance', [PrincipalDashboardController::class, 'classPerformance']);
    Route::get('/risk-students', [PrincipalDashboardController::class, 'riskStudents']);
});
