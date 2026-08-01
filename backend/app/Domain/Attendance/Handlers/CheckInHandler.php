<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Domain\Attendance\Commands\CheckInCommand;
use App\Domain\Attendance\ValueObjects\AttendanceTimeWindow;
use App\Domain\Attendance\ValueObjects\GeoFence;
use App\Domain\Shared\CommandHandler;
use App\Models\Attendance;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Check-In Handler
 * 
 * Handles the CheckInCommand by:
 * 1. Loading or creating the attendance aggregate
 * 2. Enforcing business rules (time window, geofence)
 * 3. Executing the check-in operation
 * 4. Persisting changes
 * 5. Dispatching domain events
 * 
 * This is part of the WRITE MODEL in CQRS architecture.
 * 
 * @package App\Domain\Attendance\Handlers
 */
class CheckInHandler implements CommandHandler
{
    /**
     * Handle the check-in command
     * 
     * @param CheckInCommand $command
     * @return Attendance
     * @throws \Exception
     */
    public function handle($command): Attendance
    {
        if (!$command instanceof CheckInCommand) {
            throw new \InvalidArgumentException('Invalid command type');
        }

        return DB::transaction(function () use ($command) {
            // Load schedule to get time window and geofence
            $schedule = Schedule::with('school')->findOrFail($command->scheduleId);
            
            // Create value objects for validation
            $timeWindow = null;
            if ($schedule->start_time && $schedule->end_time) {
                $timeWindow = new AttendanceTimeWindow(
                    scheduledStart: \Carbon\CarbonImmutable::parse($schedule->start_time),
                    scheduledEnd: \Carbon\CarbonImmutable::parse($schedule->end_time),
                    preWindowMinutes: (int) ($schedule->pre_window_minutes ?? 10),
                    postWindowMinutes: (int) ($schedule->late_threshold ?? 15),
                );
            }

            $geoFence = null;
            if ($schedule->school && $schedule->school->latitude && $schedule->school->longitude) {
                $geoFence = new GeoFence(
                    lat: (float) $schedule->school->latitude,
                    lng: (float) $schedule->school->longitude,
                    radiusMeters: (int) ($schedule->school->radius_meters ?? 100),
                );
            }

            // Find existing or create new attendance aggregate
            $existingAttendance = Attendance::where([
                'student_id' => $command->studentId,
                'schedule_id' => $command->scheduleId,
                'attendance_date' => $command->attendanceDate,
            ])->first();

            if ($existingAttendance) {
                $aggregate = AttendanceAggregate::fromModel($existingAttendance);
            } else {
                $aggregate = AttendanceAggregate::create(
                    studentId: $command->studentId,
                    scheduleId: $command->scheduleId,
                    schoolId: $command->schoolId,
                    attendanceDate: $command->attendanceDate,
                    classId: $command->classId,
                    source: $command->source,
                    requestId: $command->requestId,
                );
            }

            // Execute check-in with business rule validation
            $aggregate->checkIn(
                time: $command->checkInTime,
                lat: $command->latitude,
                lng: $command->longitude,
                window: $timeWindow,
                geoFence: $geoFence,
                recordedBy: $command->recordedBy,
                deviceId: $command->deviceId,
            );

            // Persist the model
            $model = $aggregate->getModel();
            $model->save();

            // Dispatch domain events
            foreach ($aggregate->releasePendingEvents() as $event) {
                event($event);
            }

            return $model;
        });
    }
}
