<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\School;
use Carbon\Carbon;

/**
 * TimezoneHelper - Centralized timezone handling utility
 *
 * Provides consistent timezone-aware date/time operations across the application.
 * Prevents timezone-related bugs by enforcing school-specific or application-default timezones.
 */
class TimezoneHelper
{
    /**
     * Get current datetime with specified or default timezone
     *
     * @param string|null $timezone Timezone identifier (e.g., 'Asia/Jakarta'). Uses app.timezone if null.
     */
    public static function now(?string $timezone = null): Carbon
    {
        return now($timezone ?? config('app.timezone'));
    }

    /**
     * Get current datetime for a specific school's timezone
     *
     * @param School $school School model with timezone attribute
     */
    public static function schoolNow(School $school): Carbon
    {
        return now($school->timezone ?? config('app.timezone'));
    }

    /**
     * Parse a date string with specified or default timezone
     *
     * @param string      $date     Date string to parse
     * @param string|null $timezone Timezone identifier. Uses app.timezone if null.
     */
    public static function parse(string $date, ?string $timezone = null): Carbon
    {
        return Carbon::parse($date, $timezone ?? config('app.timezone'));
    }

    /**
     * Get today's date in specified or default timezone
     *
     * @param string|null $timezone Timezone identifier. Uses app.timezone if null.
     */
    public static function today(School|string|null $timezoneOrSchool = null): Carbon|string
    {
        if ($timezoneOrSchool instanceof School) {
            return self::schoolNow($timezoneOrSchool)->toDateString();
        }

        return self::now($timezoneOrSchool)->startOfDay();
    }

    /**
     * Create a Carbon instance from a time string using the school's timezone.
     *
     * @param string      $timeString Time in H:i:s format (e.g. "08:00:00")
     * @param School|null $school     School model with timezone attribute
     * @param string|null $date       Date in Y-m-d format (defaults to today)
     */
    public static function timeFromString(string $timeString, ?School $school = null, ?string $date = null): Carbon
    {
        $timezone = $school?->timezone ?? config('app.timezone');

        $date ??= self::now($timezone)->toDateString();

        return Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$timeString}", $timezone);
    }

    /**
     * Check if a time is between start and end times (inclusive).
     *
     * @param Carbon $current Time to check
     * @param Carbon $start   Start time
     * @param Carbon $end     End time
     */
    public static function isBetween(Carbon $current, Carbon $start, Carbon $end): bool
    {
        return $current->between($start, $end);
    }

    /**
     * Get today's date for a specific school's timezone
     *
     * @param School $school School model with timezone attribute
     */
    public static function schoolToday(School $school): Carbon
    {
        return self::schoolNow($school)->startOfDay();
    }

    /**
     * Get today's date string (Y-m-d) based on School's timezone.
     * Critical for attendance_date consistency.
     */
    public static function todayString(School $school): string
    {
        return self::schoolNow($school)->toDateString();
    }

    /**
     * Convert a Carbon instance to a specific timezone
     *
     * @param Carbon      $date     Date to convert
     * @param string|null $timezone Target timezone. Uses app.timezone if null.
     */
    public static function convertTo(Carbon $date, ?string $timezone = null): Carbon
    {
        return $date->copy()->setTimezone($timezone ?? config('app.timezone'));
    }

    /**
     * Convert a Carbon instance to school's timezone
     *
     * @param Carbon $date   Date to convert
     * @param School $school School model with timezone attribute
     */
    public static function convertToSchool(Carbon $date, School $school): Carbon
    {
        return $date->copy()->setTimezone($school->timezone ?? config('app.timezone'));
    }

    /**
     * Get the default application timezone
     */
    public static function defaultTimezone(): string
    {
        return config('app.timezone') ?? 'UTC';
    }
}
