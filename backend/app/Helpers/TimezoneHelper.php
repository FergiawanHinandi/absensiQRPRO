<?php

namespace App\Helpers;

use App\Models\School;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * TimezoneHelper - Centralized timezone-aware datetime operations
 * 
 * This helper ensures consistent timezone handling across the application
 * to prevent date mismatch bugs in attendance tracking.
 * 
 * Usage:
 * - Use TimezoneHelper::now() instead of date() or time()
 * - Use TimezoneHelper::schoolNow($school) instead of Carbon::now()
 * - Use TimezoneHelper::parse() instead of strtotime() or Carbon::parse()
 * 
 * @package App\Helpers
 */
class TimezoneHelper
{
    /**
     * Get current datetime with optional timezone override
     * 
     * Returns current datetime in the specified timezone or falls back
     * to application default timezone from config.
     * 
     * @param string|null $timezone Timezone identifier (e.g., 'Asia/Jakarta')
     * @return Carbon Current datetime in specified timezone
     * 
     * @example
     * // Use app timezone
     * $now = TimezoneHelper::now();
     * 
     * // Use specific timezone
     * $now = TimezoneHelper::now('Asia/Jakarta');
     * 
     * // Get date string
     * $today = TimezoneHelper::now()->toDateString(); // "2026-02-09"
     */
    public static function now(?string $timezone = null): Carbon
    {
        return now($timezone ?? Config::get('app.timezone'));
    }
    
    /**
     * Get current datetime in school's timezone
     * 
     * This is the primary method for school-scoped datetime operations.
     * Always use this when working with school-specific data like attendance.
     * 
     * @param School $school School instance with timezone property
     * @return Carbon Current datetime in school's timezone
     * 
     * @example
     * // Get current time for school
     * $now = TimezoneHelper::schoolNow($school);
     * 
     * // Get today's date for attendance
     * $today = TimezoneHelper::schoolNow($school)->toDateString();
     * 
     * // Check if within schedule time
     * $now = TimezoneHelper::schoolNow($school);
     * if ($now->between($schedule->start_time, $schedule->end_time)) {
     *     // Within schedule
     * }
     */
    public static function schoolNow(School $school): Carbon
    {
        return now($school->timezone ?? Config::get('app.timezone'));
    }
    
    /**
     * Parse datetime string with timezone awareness
     * 
     * Converts a datetime string to Carbon instance in the specified timezone.
     * Use this instead of strtotime() or Carbon::parse() for timezone safety.
     * 
     * @param string $date Datetime string to parse
     * @param string|null $timezone Timezone identifier (optional)
     * @return Carbon Parsed datetime in specified timezone
     * 
     * @example
     * // Parse with app timezone
     * $date = TimezoneHelper::parse('2026-02-09 08:00:00');
     * 
     * // Parse with specific timezone
     * $date = TimezoneHelper::parse('2026-02-09 08:00:00', 'Asia/Jakarta');
     * 
     * // Parse and compare
     * $scheduleStart = TimezoneHelper::parse($schedule->start_time, $school->timezone);
     * if (TimezoneHelper::schoolNow($school)->greaterThan($scheduleStart)) {
     *     // Schedule has started
     * }
     */
    public static function parse(string $date, ?string $timezone = null): Carbon
    {
        return Carbon::parse($date, $timezone ?? Config::get('app.timezone'));
    }
    
    /**
     * Get today's date string in school's timezone
     * 
     * Convenience method for getting Y-m-d formatted date string.
     * Critical for attendance_date consistency.
     * 
     * @param School $school School instance
     * @return string Date in Y-m-d format
     * 
     * @example
     * $today = TimezoneHelper::today($school); // "2026-02-09"
     * 
     * // Use in queries
     * $attendances = Attendance::where('attendance_date', TimezoneHelper::today($school))
     *     ->where('school_id', $school->id)
     *     ->get();
     */
    public static function today(School $school): string
    {
        return self::schoolNow($school)->toDateString();
    }
    
    /**
     * Create Carbon instance from time string using school's timezone
     * 
     * Useful for comparing schedule times with current time.
     * Combines a date and time string into a full datetime.
     * 
     * @param string $timeString Time in H:i:s format (e.g., "08:00:00")
     * @param School $school School instance
     * @param string|null $date Date in Y-m-d format (defaults to today)
     * @return Carbon Full datetime in school's timezone
     * 
     * @example
     * // Create time for today
     * $startTime = TimezoneHelper::timeFromString('08:00:00', $school);
     * 
     * // Create time for specific date
     * $endTime = TimezoneHelper::timeFromString('10:00:00', $school, '2026-02-09');
     * 
     * // Check if current time is within schedule
     * $now = TimezoneHelper::schoolNow($school);
     * $start = TimezoneHelper::timeFromString($schedule->start_time, $school);
     * $end = TimezoneHelper::timeFromString($schedule->end_time, $school);
     * 
     * if ($now->between($start, $end)) {
     *     // Within schedule time
     * }
     */
    public static function timeFromString(
        string $timeString,
        School $school,
        ?string $date = null
    ): Carbon {
        $timezone = $school->timezone ?? Config::get('app.timezone');
        $date = $date ?? self::today($school);
        
        return Carbon::createFromFormat(
            'Y-m-d H:i:s',
            "{$date} {$timeString}",
            $timezone
        );
    }
    
    /**
     * Check if current time is between start and end times (inclusive)
     * 
     * @param Carbon $current Current time to check
     * @param Carbon $start Start time (inclusive)
     * @param Carbon $end End time (inclusive)
     * @return bool True if current is between start and end
     * 
     * @example
     * $now = TimezoneHelper::schoolNow($school);
     * $start = TimezoneHelper::timeFromString('08:00:00', $school);
     * $end = TimezoneHelper::timeFromString('10:00:00', $school);
     * 
     * if (TimezoneHelper::isBetween($now, $start, $end)) {
     *     // Current time is within schedule
     * }
     */
    public static function isBetween(Carbon $current, Carbon $start, Carbon $end): bool
    {
        return $current->greaterThanOrEqualTo($start) && $current->lessThanOrEqualTo($end);
    }
}
