<?php

/**
 * TIMEZONE-AWARE HELPER FUNCTIONS
 * 
 * These helpers ensure all datetime operations respect school timezones
 * for accurate attendance tracking across different regions.
 */

if (!function_exists('school_now')) {
    /**
     * Get current datetime in school's timezone
     * 
     * This function should be used instead of now() or Carbon::now()
     * to ensure timezone-aware datetime operations.
     * 
     * @param \App\Models\School|null $school School instance with timezone property
     * @return \Illuminate\Support\Carbon
     * 
     * @example
     * // With school object
     * $currentTime = school_now($school);
     * 
     * // Fallback to app timezone if school is null
     * $currentTime = school_now(null);
     */
    function school_now($school = null): \Illuminate\Support\Carbon
    {
        $timezone = $school?->timezone ?? config('app.timezone', 'UTC');
        
        return now($timezone);
    }
}

if (!function_exists('school_today')) {
    /**
     * Get today's date string in school's timezone
     * 
     * @param \App\Models\School|null $school
     * @return string Date in Y-m-d format
     * 
     * @example
     * $today = school_today($school); // "2026-02-09"
     */
    function school_today($school = null): string
    {
        return school_now($school)->toDateString();
    }
}

if (!function_exists('school_time_from_string')) {
    /**
     * Create Carbon instance from time string using school's timezone
     * 
     * Useful for comparing schedule times with current time.
     * 
     * @param string $timeString Time in H:i:s format (e.g., "08:00:00")
     * @param \App\Models\School|null $school
     * @param string|null $date Date in Y-m-d format (defaults to today)
     * @return \Illuminate\Support\Carbon
     * 
     * @example
     * $startTime = school_time_from_string('08:00:00', $school);
     * $endTime = school_time_from_string('10:00:00', $school, '2026-02-09');
     */
    function school_time_from_string(
        string $timeString,
        $school = null,
        ?string $date = null
    ): \Illuminate\Support\Carbon {
        $timezone = $school?->timezone ?? config('app.timezone', 'UTC');
        $date = $date ?? school_today($school);
        
        return \Carbon\Carbon::createFromFormat(
            'Y-m-d H:i:s',
            "{$date} {$timeString}",
            $timezone
        );
    }
}

if (!function_exists('school_parse_datetime')) {
    /**
     * Parse datetime string in school's timezone
     * 
     * @param string $datetime
     * @param \App\Models\School|null $school
     * @return \Illuminate\Support\Carbon
     * 
     * @example
     * $parsed = school_parse_datetime('2026-02-09 08:00:00', $school);
     */
    function school_parse_datetime(string $datetime, $school = null): \Illuminate\Support\Carbon
    {
        $timezone = $school?->timezone ?? config('app.timezone', 'UTC');
        
        return \Carbon\Carbon::parse($datetime, $timezone);
    }
}

if (!function_exists('is_time_between_inclusive')) {
    /**
     * Check if current time is between start and end times (inclusive)
     * 
     * @param \Illuminate\Support\Carbon $current
     * @param \Illuminate\Support\Carbon $start
     * @param \Illuminate\Support\Carbon $end
     * @return bool
     * 
     * @example
     * $now = school_now($school);
     * $start = school_time_from_string('08:00:00', $school);
     * $end = school_time_from_string('10:00:00', $school);
     * 
     * if (is_time_between_inclusive($now, $start, $end)) {
     *     // Current time is within schedule
     * }
     */
    function is_time_between_inclusive(
        \Illuminate\Support\Carbon $current,
        \Illuminate\Support\Carbon $start,
        \Illuminate\Support\Carbon $end
    ): bool {
        return $current->greaterThanOrEqualTo($start) && $current->lessThanOrEqualTo($end);
    }
}
