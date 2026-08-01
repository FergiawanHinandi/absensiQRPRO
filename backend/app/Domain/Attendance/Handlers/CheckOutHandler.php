<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Domain\Attendance\Commands\CheckOutCommand;
use App\Domain\Shared\CommandHandler;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;

/**
 * Check-Out Handler
 * 
 * Handles the CheckOutCommand by:
 * 1. Loading the attendance aggregate
 * 2. Executing the check-out operation
 * 3. Persisting changes
 * 4. Dispatching domain events
 * 
 * @package App\Domain\Attendance\Handlers
 */
class CheckOutHandler implements CommandHandler
{
    public function handle($command): Attendance
    {
        if (!$command instanceof CheckOutCommand) {
            throw new \InvalidArgumentException('Invalid command type');
        }

        return DB::transaction(function () use ($command) {
            // Load existing attendance
            $attendance = Attendance::findOrFail($command->attendanceId);
            
            // Reconstitute aggregate
            $aggregate = AttendanceAggregate::fromModel($attendance);

            // Execute check-out
            $aggregate->checkOut(
                time: $command->checkOutTime,
                lat: $command->latitude,
                lng: $command->longitude,
                recordedBy: $command->recordedBy,
                deviceId: $command->deviceId,
            );

            // Persist
            $model = $aggregate->getModel();
            $model->save();

            // Dispatch events
            foreach ($aggregate->releasePendingEvents() as $event) {
                event($event);
            }

            return $model;
        });
    }
}
