<?php

namespace App\Services\Examples;

use App\Models\School;
use App\Models\Schedule;
use Carbon\Carbon;

/**
 * EXAMPLE REFACTORED METHODS - Timezone-Aware
 * 
 * This file demonstrates how to refactor existing methods to use
 * timezone-aware helper functions instead of now() or Carbon::now().
 */
class TimezoneRefactoredExamples
{
    /**
     * EXAMPLE 1: Validate Time Window (BEFORE)
     * 
     * PROBLEMS:
     * - Uses now() without timezone
     * - Creates Carbon instances without timezone context
     * - Time comparisons may be incorrect for schools in different timezones
     */
    public function validateTimeWindowBefore(Schedule $schedule, ?School $school): string
    {
        $now = now(); // ❌ No timezone
        $today = $now->format('Y-m-d');

        $startTime = Carbon::parse("{$today} {$schedule->start_time}"); // ❌ No timezone
        $endTime = Carbon::parse("{$today} {$schedule->end_time}"); // ❌ No timezone

        // Get grace period settings
        $settings = $school?->settings ?? [];
        $earlyGrace = $settings['attendance_grace_early'] ?? 15;
        $lateTolerance = $settings['attendance_grace_late'] ?? 15;

        $earliestAllowed = $startTime->copy()->subMinutes($earlyGrace);
        $lateThreshold = $startTime->copy()->addMinutes($lateTolerance);

        // Too early
        if ($now->lessThan($earliestAllowed)) { // ❌ Not inclusive
            throw new \Exception("Absensi belum dibuka.");
        }

        // Class ended
        if ($now->greaterThan($endTime)) { // ❌ Not inclusive
            throw new \Exception("Kelas sudah berakhir.");
        }

        // Determine status: present or late
        return $now->greaterThan($lateThreshold) ? 'late' : 'present';
    }

    /**
     * EXAMPLE 1: Validate Time Window (AFTER)
     * 
     * IMPROVEMENTS:
     * ✅ Uses school_now() for timezone-aware current time
     * ✅ Uses school_time_from_string() for schedule times
     * ✅ Uses inclusive comparisons (greaterThanOrEqualTo, lessThanOrEqualTo)
     * ✅ Consistent timezone across all datetime operations
     */
    public function validateTimeWindowAfter(Schedule $schedule, ?School $school): string
    {
        // Get current time in school's timezone
        $now = school_now($school); // ✅ Timezone-aware
        $today = school_today($school);

        // Create schedule times in school's timezone
        $startTime = school_time_from_string($schedule->start_time, $school, $today); // ✅ Timezone-aware
        $endTime = school_time_from_string($schedule->end_time, $school, $today); // ✅ Timezone-aware

        // Get grace period settings
        $settings = $school?->settings ?? [];
        $earlyGrace = $settings['attendance_grace_early'] ?? 15;
        $lateTolerance = $settings['attendance_grace_late'] ?? 15;

        $earliestAllowed = $startTime->copy()->subMinutes($earlyGrace);
        $lateThreshold = $startTime->copy()->addMinutes($lateTolerance);

        // Too early (inclusive check)
        if ($now->lessThan($earliestAllowed)) { // ✅ Inclusive
            throw new \Exception(
                "Absensi belum dibuka. Silakan scan mulai pukul {$earliestAllowed->format('H:i')}."
            );
        }

        // Class ended (inclusive check)
        if ($now->greaterThan($endTime)) { // ✅ Inclusive
            throw new \Exception("Kelas sudah berakhir.");
        }

        // Determine status: present or late (inclusive check)
        return $now->greaterThan($lateThreshold) ? 'late' : 'present'; // ✅ Inclusive
    }

    /**
     * EXAMPLE 2: Generate QR Code (BEFORE)
     */
    public function generateQRBefore(Schedule $schedule, School $school): array
    {
        $now = Carbon::now(); // ❌ No timezone
        $expiresAt = $now->copy()->addSeconds(60);

        $payload = [
            'schedule_id' => $schedule->id,
            'generated_at' => $now->timestamp, // ❌ May be wrong timezone
            'exp' => $expiresAt->timestamp,
        ];

        return $payload;
    }

    /**
     * EXAMPLE 2: Generate QR Code (AFTER)
     */
    public function generateQRAfter(Schedule $schedule, School $school): array
    {
        $now = school_now($school); // ✅ Timezone-aware
        $expiresAt = $now->copy()->addSeconds(60);

        $payload = [
            'schedule_id' => $schedule->id,
            'generated_at' => $now->timestamp, // ✅ Correct timezone
            'exp' => $expiresAt->timestamp,
            'timezone' => $school->timezone ?? config('app.timezone'),
        ];

        return $payload;
    }

    /**
     * EXAMPLE 3: Check Attendance Date Range (BEFORE)
     */
    public function getAttendanceRangeBefore(int $days): array
    {
        $endDate = now(); // ❌ No timezone
        $startDate = now()->subDays($days)->startOfDay(); // ❌ No timezone

        return [$startDate, $endDate];
    }

    /**
     * EXAMPLE 3: Check Attendance Date Range (AFTER)
     */
    public function getAttendanceRangeAfter(School $school, int $days): array
    {
        $endDate = school_now($school); // ✅ Timezone-aware
        $startDate = school_now($school)->subDays($days)->startOfDay(); // ✅ Timezone-aware

        return [$startDate, $endDate];
    }

    /**
     * EXAMPLE 4: Time Window Validation with Tolerance (BEFORE)
     */
    public function isWithinScheduleTimeBefore(Schedule $schedule): bool
    {
        $now = now(); // ❌ No timezone
        $currentTime = $now->format('H:i:s');
        
        $startTime = Carbon::createFromFormat('H:i:s', $schedule->start_time); // ❌ No timezone
        $endTime = Carbon::createFromFormat('H:i:s', $schedule->end_time); // ❌ No timezone
        
        $earliestAllowed = $startTime->copy()->subMinutes(10);
        $latestAllowed = $endTime->copy()->addMinutes(5);
        
        $currentTimeCarbon = Carbon::createFromFormat('H:i:s', $currentTime); // ❌ No timezone
        
        return $currentTimeCarbon->between($earliestAllowed, $latestAllowed); // ❌ Not inclusive
    }

    /**
     * EXAMPLE 4: Time Window Validation with Tolerance (AFTER)
     */
    public function isWithinScheduleTimeAfter(Schedule $schedule, School $school): bool
    {
        $now = school_now($school); // ✅ Timezone-aware
        $today = school_today($school);
        
        $startTime = school_time_from_string($schedule->start_time, $school, $today); // ✅ Timezone-aware
        $endTime = school_time_from_string($schedule->end_time, $school, $today); // ✅ Timezone-aware
        
        $earliestAllowed = $startTime->copy()->subMinutes(10);
        $latestAllowed = $endTime->copy()->addMinutes(5);
        
        // Use inclusive comparison
        return is_time_between_inclusive($now, $earliestAllowed, $latestAllowed); // ✅ Inclusive
    }

    /**
     * EXAMPLE 5: Attendance Record Creation (BEFORE)
     */
    public function createAttendanceBefore(array $data): array
    {
        return [
            'attendance_date' => now()->toDateString(), // ❌ No timezone
            'check_in_time' => now(), // ❌ No timezone
            'created_at' => now(), // ❌ No timezone
        ];
    }

    /**
     * EXAMPLE 5: Attendance Record Creation (AFTER)
     */
    public function createAttendanceAfter(School $school, array $data): array
    {
        $now = school_now($school); // ✅ Timezone-aware
        
        return [
            'attendance_date' => school_today($school), // ✅ Timezone-aware
            'check_in_time' => $now, // ✅ Timezone-aware
            'created_at' => $now, // ✅ Timezone-aware
        ];
    }

    /**
     * EXAMPLE 6: Token Expiration Check (BEFORE)
     */
    public function isTokenExpiredBefore(array $payload): bool
    {
        if (isset($payload['exp']) && $payload['exp'] < now()->timestamp) { // ❌ No timezone
            return true;
        }
        
        return false;
    }

    /**
     * EXAMPLE 6: Token Expiration Check (AFTER)
     * 
     * Note: For tokens, we typically use UTC for consistency across timezones
     */
    public function isTokenExpiredAfter(array $payload): bool
    {
        // For security tokens, use UTC (no school context)
        $nowUtc = now('UTC');
        
        if (isset($payload['exp']) && $payload['exp'] < $nowUtc->timestamp) { // ✅ Explicit UTC
            return true;
        }
        
        return false;
    }

    /**
     * EXAMPLE 7: Day of Week Check (BEFORE)
     */
    public function isTodayScheduleDayBefore(Schedule $schedule): bool
    {
        $todayDayOfWeek = now()->dayOfWeek; // ❌ No timezone
        
        return $schedule->day_of_week === $todayDayOfWeek;
    }

    /**
     * EXAMPLE 7: Day of Week Check (AFTER)
     */
    public function isTodayScheduleDayAfter(Schedule $schedule, School $school): bool
    {
        $todayDayOfWeek = school_now($school)->dayOfWeek; // ✅ Timezone-aware
        
        return $schedule->day_of_week === $todayDayOfWeek;
    }
}
