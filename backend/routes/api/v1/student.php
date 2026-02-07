<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Student\StudentDashboardController;

Route::middleware(['role:student'])->prefix('student')->group(function () {
    Route::get('/dashboard', [StudentDashboardController::class, 'index'])
        ->middleware('ability:student:view_dashboard');
    
    Route::get('/profile', [StudentDashboardController::class, 'profile'])
        ->middleware('ability:student:view_dashboard');

    Route::get('/attendance-history', [StudentDashboardController::class, 'history']);
    Route::get('/today-schedule', [StudentDashboardController::class, 'getTodayTimeline']);
    Route::get('/monthly-summary', [StudentDashboardController::class, 'getMonthlySummary']);
    Route::get('/notifications', [StudentDashboardController::class, 'notifications']);
    Route::get('/gamification', [StudentDashboardController::class, 'getGamificationStats']);
    Route::post('/claim-reward', [StudentDashboardController::class, 'claimRewardCertificate']);
});
