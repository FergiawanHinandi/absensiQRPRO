<?php

use Illuminate\Support\Facades\Route;

// use App\Http\Controllers\Web\Admin\HealthDashboardController;

Route::get('/', function () {
    return view('welcome');
});

// Route::middleware('auth')->group(function () {
//     Route::get('/admin/health', [HealthDashboardController::class, 'index'])->name('admin.health');
//     Route::get('/admin/calendars', [HealthDashboardController::class, 'calendars'])->name('admin.calendars');
//     Route::get('/admin/calendars/export', [HealthDashboardController::class, 'calendarExport'])->name('admin.calendars.export');
//     Route::post('/admin/calendars', [HealthDashboardController::class, 'calendarStore'])->name('admin.calendars.store');
//     Route::put('/admin/calendars/{id}', [HealthDashboardController::class, 'calendarUpdate'])->name('admin.calendars.update');
//     Route::delete('/admin/calendars/{id}', [HealthDashboardController::class, 'calendarDelete'])->name('admin.calendars.delete');
// });
