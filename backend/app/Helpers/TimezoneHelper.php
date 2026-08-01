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
    public static function today(?string $timezone = null): Carbon
    {
        return self::now($timezone)->startOfDay();
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
