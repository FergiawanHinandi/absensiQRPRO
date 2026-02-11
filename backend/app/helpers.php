<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

if (!function_exists('school_now')) {
    /**
     * Get current time in school's timezone
     * 
     * This helper ensures all time operations respect the school's timezone
     * to prevent issues with multi-timezone deployments.
     * 
     * @param \App\Models\School|null $school School instance (optional, will use auth user's school if null)
     * @return \Carbon\Carbon Current time in school's timezone
     * 
     * @example
     * // In controller/service
     * $now = school_now($school);
     * 
     * // Using authenticated user's school
     * $now = school_now();
     */
    function school_now($school = null): Carbon
    {
        // If no school provided, try to get from authenticated user
        if ($school === null && Auth::check()) {
            $user = Auth::user();
            $school = $user->school ?? null;
        }

        // Get timezone from school or fall back to app default
        $timezone = $school?->timezone ?? config('app.timezone', 'UTC');

        return now($timezone);
    }
}

if (!function_exists('school_today')) {
    /**
     * Get today's date in school's timezone
     * 
     * @param \App\Models\School|null $school
     * @return \Carbon\Carbon Today's date at 00:00:00 in school's timezone
     */
    function school_today($school = null): Carbon
    {
        return school_now($school)->startOfDay();
    }
}

if (!function_exists('school_parse')) {
    /**
     * Parse a date/time string in school's timezone
     * 
     * @param string $time Time string to parse
     * @param \App\Models\School|null $school
     * @return \Carbon\Carbon Parsed time in school's timezone
     * 
     * @example
     * $startTime = school_parse('08:00:00', $school);
     */
    function school_parse(string $time, $school = null): Carbon
    {
        $timezone = $school?->timezone ?? config('app.timezone', 'UTC');
        return Carbon::parse($time, $timezone);
    }
}

if (!function_exists('school_create_from_format')) {
    /**
     * Create Carbon instance from format in school's timezone
     * 
     * @param string $format Date format
     * @param string $time Time string
     * @param \App\Models\School|null $school
     * @return \Carbon\Carbon
     * 
     * @example
     * $scheduleTime = school_create_from_format(
     *     'Y-m-d H:i:s',
     *     $now->format('Y-m-d') . ' ' . $schedule->start_time,
     *     $school
     * );
     */
    function school_create_from_format(string $format, string $time, $school = null): Carbon
    {
        $timezone = $school?->timezone ?? config('app.timezone', 'UTC');
        return Carbon::createFromFormat($format, $time, $timezone);
    }
}

if (!function_exists('school_timezone')) {
    /**
     * Get school's timezone string
     * 
     * @param \App\Models\School|null $school
     * @return string Timezone identifier (e.g., 'Asia/Jakarta')
     */
    function school_timezone($school = null): string
    {
        if ($school === null && Auth::check()) {
            $user = Auth::user();
            $school = $user->school ?? null;
        }

        return $school?->timezone ?? config('app.timezone', 'UTC');
    }
}
