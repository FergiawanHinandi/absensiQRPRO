<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\LeaderboardController;

/*
|--------------------------------------------------------------------------
| Leaderboard API Routes
|--------------------------------------------------------------------------
|
| Accessible by all authenticated users (students, teachers, parents).
| School-scoped queries are handled by the controller.
|
*/

Route::prefix('leaderboard')->group(function () {
    // Student class leaderboard - Top 10 by points & streak
    Route::get('/', [LeaderboardController::class, 'index']);

    // Class vs class competition by monthly attendance rate
    Route::get('/class-competition', [LeaderboardController::class, 'classCompetition']);

    // Official school leaderboard from snapshot history
    Route::get('/official', [LeaderboardController::class, 'officialLeaderboard']);

    // All-time records (longest streaks, perfect attendance)
    Route::get('/hall-of-fame', [LeaderboardController::class, 'hallOfFame']);
});
