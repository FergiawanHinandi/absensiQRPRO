<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Domain\Attendance\Rules\NoDuplicateAttendanceRule;
use App\Domain\Attendance\ValueObjects\AttendanceTimeWindow;
use App\Domain\Attendance\ValueObjects\GeoFence;
use App\Models\Schedule;
use Carbon\CarbonImmutable;

/**
 * Domain service for attendance check-in orchestration.
 *
 * Coordinates between the aggregate root, business rules,
 * and schedule data to perform a check-in operation.
 */
class AttendanceCheckInDomainService
{
    public function __construct(
        private readonly NoDuplicateAttendanceRule $noDuplicateRule,
    ) {}

    /**
     * Create and check-in an attendance aggregate from schedule data.
     */
    public function checkIn(
        int $studentId,
        Schedule $schedule,
        int $schoolId,
        CarbonImmutable $time,
        ?float $lat,
        ?float $lng,
        ?int $recordedBy = null,
        ?string $deviceId = null,
        ?string $source = null,
        ?string $requestId = null,
    ): AttendanceAggregate {
        $attendanceDate = $time->format('Y-m-d');

        // Enforce no duplicate
        $this->noDuplicateRule->enforce($studentId, $schedule->id, $attendanceDate);

        // Build time window from schedule
        $window = $this->buildTimeWindow($schedule, $time);

        // Build geo fence if school has location data
        $geoFence = $this->buildGeoFence($schedule);

        // Create aggregate and check-in
        $aggregate = AttendanceAggregate::create(
            studentId: $studentId,
            scheduleId: $schedule->id,
            schoolId: $schoolId,
            attendanceDate: $attendanceDate,
            classId: $schedule->class_id ?? null,
            source: $source,
            requestId: $requestId,
        );

        $aggregate->checkIn(
            time: $time,
            lat: $lat,
            lng: $lng,
            window: $window,
            geoFence: $geoFence,
            recordedBy: $recordedBy,
            deviceId: $deviceId,
        );

        return $aggregate;
    }

    private function buildTimeWindow(Schedule $schedule, CarbonImmutable $time): AttendanceTimeWindow
    {
        $date = $time->format('Y-m-d');
        $startTime = $schedule->start_time ?? '08:00';
        $endTime = $schedule->end_time ?? '09:00';

        return new AttendanceTimeWindow(
            scheduledStart: CarbonImmutable::parse("{$date} {$startTime}"),
            scheduledEnd: CarbonImmutable::parse("{$date} {$endTime}"),
            preWindowMinutes: (int) config('attendance.pre_window_minutes', 10),
            postWindowMinutes: (int) config('attendance.post_window_minutes', 5),
        );
    }

    private function buildGeoFence(Schedule $schedule): ?GeoFence
    {
        $school = $schedule->school ?? $schedule->class?->school ?? null;

        if (! $school || ! $school->latitude || ! $school->longitude) {
            return null;
        }

        return new GeoFence(
            lat: (float) $school->latitude,
            lng: (float) $school->longitude,
            radiusMeters: (int) ($school->geo_fence_radius ?? config('attendance.geo_fence_radius', 100)),
        );
    }
}
