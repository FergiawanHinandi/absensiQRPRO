<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Student\StudentDashboardController;

/*
|--------------------------------------------------------------------------
| Student API Routes
|--------------------------------------------------------------------------
|
| SECURITY MIDDLEWARE STACK:
| 1. auth:sanctum - Authentication (inherited from api.php)
| 2. role:student - Role-based access control
| 3. ability:xxx - Fine-grained permission checks
|
| IDOR PROTECTION:
| - All queries are scoped by school_id via BelongsToSchool trait
| - Student can only access their own records (enforced in controllers)
|
*/

Route::middleware(['role:student'])->prefix('student')->group(function () {
    // Dashboard - requires view permission
    Route::get('/dashboard', [StudentDashboardController::class, 'index'])
        ->middleware('ability:student:view_dashboard');
    
    // Profile - student can view own profile
    Route::get('/profile', [StudentDashboardController::class, 'profile'])
        ->middleware('ability:student:view_dashboard');

    // Attendance - student can view own attendance history
    Route::get('/attendance-history', [StudentDashboardController::class, 'history'])
        ->middleware('ability:attendance:view_own');
    
    // Schedule - student can view their schedule
    Route::get('/today-schedule', [StudentDashboardController::class, 'getTodayTimeline'])
        ->middleware('ability:schedule:view_own');
    
    // Summary - monthly attendance summary
    Route::get('/monthly-summary', [StudentDashboardController::class, 'getMonthlySummary'])
        ->middleware('ability:attendance:view_own');
    
    // Notifications - student can view their notifications
    Route::get('/notifications', [StudentDashboardController::class, 'notifications'])
        ->middleware('ability:notification:view_own');
    
    // Gamification - view points, badges, streaks
    Route::get('/gamification', [StudentDashboardController::class, 'getGamificationStats'])
        ->middleware('ability:student:view_dashboard');
    
    // Rewards - claim certificate/reward
    Route::post('/claim-reward', [StudentDashboardController::class, 'claimRewardCertificate'])
        ->middleware('ability:reward:claim');
});
