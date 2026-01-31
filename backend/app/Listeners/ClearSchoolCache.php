<?php

namespace App\Listeners;

use App\Events\AcademicYearActivated;

class ClearSchoolCache
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(AcademicYearActivated $event): void
    {
        $schoolId = $event->schoolId;

        // Clear Schedule Cache
        \Illuminate\Support\Facades\Cache::forget("schedules_school_{$schoolId}");

        // Clear Attendance Summary Cache (Wildcard clearing is tricky with file cache,
        // so we assume key naming conventions or specific keys if known.
        // For now, let's clear commonly used keys)
        \Illuminate\Support\Facades\Cache::forget("attendance_summary_school_{$schoolId}_today");
        \Illuminate\Support\Facades\Cache::forget("attendance_summary_school_{$schoolId}_month");

        // Clear School Calendar Cache
        \Illuminate\Support\Facades\Cache::forget("school_calendar_{$schoolId}");

        // Log the action
        \Illuminate\Support\Facades\Log::info("Cache cleared for School ID: {$schoolId} due to Academic Year Activation (ID: {$event->academicYearId})");
    }
}
