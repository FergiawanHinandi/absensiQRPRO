<?php

namespace App\Services;

use App\Exceptions\AttendanceException;
use App\Models\Schedule;
use App\Models\School;
use Carbon\Carbon;

/**
 * AttendanceTimeWindowService
 *
 * Determines whether a check-in attempt falls within the valid time window
 * and resolves the appropriate attendance status (present / late).
 *
 * Configuration keys (stored in school->settings JSON):
 *   attendance_grace_early  — minutes before start_time check-in is allowed (default 15)
 *   attendance_grace_late   — minutes after start_time before the student is marked "late" (default 15)
 *
 * Extracted from AttendanceCheckInService to isolate temporal logic.
 */
class AttendanceTimeWindowService
{
    /**
     * Validate time window and return the attendance status.
     *
     * ARCH-03 (Offline Sync): When $clientScannedAt is provided, status
     * determination (present vs late) uses the client timestamp instead
     * of server time. Boundary checks (too early, class ended) ALWAYS
     * use server time for security.
     *
     * @param  Schedule     $schedule         The schedule to validate against
     * @param  School|null  $school           The school (for timezone & settings)
     * @param  Carbon|null  $clientScannedAt  Client-side scan timestamp (ISO),
     *                                        used for status in offline-sync scenarios
     * @return string 'present' | 'late'
     *
     * @throws AttendanceException When check-in is too early or class has ended
     */
    public function validate(Schedule $schedule, ?School $school, ?Carbon $clientScannedAt = null): string
    {
        $tz = $school?->timezone ?? config('app.timezone');
        $now   = now()->timezone($tz);
        $today = $now->format('Y-m-d');

        $startTime = Carbon::parse("{$today} {$schedule->start_time}", $tz);
        $endTime   = Carbon::parse("{$today} {$schedule->end_time}", $tz);

        $settings       = $school?->settings ?? [];
        $earlyGrace     = $settings['attendance_grace_early'] ?? 15;
        $lateTolerance  = $settings['attendance_grace_late']  ?? 15;

        $earliestAllowed = $startTime->copy()->subMinutes($earlyGrace);
        $lateThreshold   = $startTime->copy()->addMinutes($lateTolerance);

        // Boundary checks ALWAYS use SERVER TIME (security).
        // Prevents client from claiming scan before class opens or after it ends.
        if ($now->lessThan($earliestAllowed)) {
            throw AttendanceException::custom(
                "Absensi belum dibuka. Silakan scan mulai pukul {$earliestAllowed->format('H:i')}."
            );
        }

        if ($now->greaterThan($endTime)) {
            throw AttendanceException::outsideTimeWindow();
        }

        // Status determination uses CLIENT timestamp when available (offline sync).
        // Ensures offline scans are correctly marked 'present' vs 'late' based
        // on when the scan actually happened, not when the server received it.
        $statusReference = $this->resolveStatusReference($clientScannedAt, $now, $tz);

        return $statusReference->greaterThan($lateThreshold) ? 'late' : 'present';
    }

    /**
     * Resolve which timestamp to use for status determination.
     *
     * Uses $clientScannedAt when:
     *   1. It is provided (not null)
     *   2. It is NOT in the future (clock tampering protection)
     *   3. It is on the same date as server time (offline sync within same day)
     *
     * Falls back to server time for any invalid client timestamp.
     */
    private function resolveStatusReference(?Carbon $clientScannedAt, Carbon $serverNow, string $tz): Carbon
    {
        if ($clientScannedAt === null) {
            return $serverNow;
        }

        // Convert client timestamp to school timezone
        $clientInTz = $clientScannedAt->copy()->timezone($tz);

        // Reject future timestamps (clock tampering detection)
        if ($clientInTz->greaterThan($serverNow)) {
            return $serverNow;
        }

        // Reject timestamps from a different date (prevent cross-day abuse)
        if ($clientInTz->format('Y-m-d') !== $serverNow->format('Y-m-d')) {
            return $serverNow;
        }

        return $clientInTz;
    }

    /**
     * Determine attendance status by comparing current server time
     * against the schedule's start_time + tolerance.
     *
     * Used by teacher-scan flows that fetch tolerance from SecurityPolicyService.
     *
     * @param  int  $toleranceMinutes  Minutes after start_time before 'late'
     */
    public function determineStatus(Schedule $schedule, int $toleranceMinutes = 15, ?School $school = null, ?Carbon $clientScannedAt = null): string
    {
        $tz = $school?->timezone ?? config('app.timezone');
        $now = now()->timezone($tz);
        
        $today = $now->format('Y-m-d');
        $lateThreshold = Carbon::parse("{$today} {$schedule->start_time}", $tz)->addMinutes($toleranceMinutes);

        $statusReference = $this->resolveStatusReference($clientScannedAt, $now, $tz);

        return $statusReference->greaterThan($lateThreshold) ? 'late' : 'present';
    }
}
