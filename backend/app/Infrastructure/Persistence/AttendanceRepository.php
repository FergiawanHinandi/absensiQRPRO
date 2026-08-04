<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Domain\Shared\DomainEvent;
use App\Models\Attendance;

/**
 * Repository for persisting Attendance aggregates.
 *
 * Saves the Eloquent model and dispatches pending domain events.
 */
class AttendanceRepository
{
    /**
     * Save an aggregate, persisting the model and dispatching events.
     */
    public function save(AttendanceAggregate $aggregate): Attendance
    {
        $model = $aggregate->getModel();
        $model->save();

        // Update event attendance IDs (they may have been 0 before save)
        $events = $aggregate->releasePendingEvents();

        foreach ($events as $event) {
            $this->enrichAndDispatch($event, $model);
        }

        return $model->fresh();
    }

    /**
     * Find an attendance record and wrap in aggregate.
     */
    public function findOrFail(int $id): AttendanceAggregate
    {
        $model = Attendance::findOrFail($id);

        return AttendanceAggregate::fromModel($model);
    }

    /**
     * Find today's record for student/schedule, or return null.
     */
    public function findTodayForStudent(int $studentId, int $scheduleId): ?AttendanceAggregate
    {
        $model = Attendance::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->whereDate('attendance_date', today())
            ->first();

        return $model ? AttendanceAggregate::fromModel($model) : null;
    }

    private function enrichAndDispatch(DomainEvent $event, Attendance $model): void
    {
        // If the event has an attendance_id of 0, it was created before save
        // Dispatch with the real ID now
        if (property_exists($event, 'attendanceId') && $event->attendanceId === 0) {
            // Create a new event with the correct ID by re-dispatching
            $eventClass = $event::class;
            $reflection = new \ReflectionClass($eventClass);
            $constructor = $reflection->getConstructor();

            if ($constructor) {
                $args = [];
                foreach ($constructor->getParameters() as $param) {
                    $name = $param->getName();
                    if ($name === 'attendanceId') {
                        $args[] = $model->id;
                    } elseif (property_exists($event, $name)) {
                        $args[] = $event->$name;
                    } elseif ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    }
                }
                $enrichedEvent = $reflection->newInstanceArgs($args);
                event($enrichedEvent);

                return;
            }
        }

        event($event);
    }
}
