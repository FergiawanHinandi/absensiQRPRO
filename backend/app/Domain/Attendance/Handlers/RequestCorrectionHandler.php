<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Domain\Attendance\Commands\RequestCorrectionCommand;
use App\Domain\Shared\CommandHandler;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;

/**
 * Request Correction Handler
 * 
 * Handles the RequestCorrectionCommand by:
 * 1. Loading the attendance aggregate
 * 2. Requesting a correction (state transition to PENDING_APPROVAL)
 * 3. Persisting changes
 * 4. Dispatching domain events
 * 
 * @package App\Domain\Attendance\Handlers
 */
class RequestCorrectionHandler implements CommandHandler
{
    public function handle($command): Attendance
    {
        if (!$command instanceof RequestCorrectionCommand) {
            throw new \InvalidArgumentException('Invalid command type');
        }

        return DB::transaction(function () use ($command) {
            // Load existing attendance
            $attendance = Attendance::findOrFail($command->attendanceId);
            
            // Reconstitute aggregate
            $aggregate = AttendanceAggregate::fromModel($attendance);

            // Request correction
            $aggregate->requestCorrection(
                reason: $command->reason,
                requesterId: $command->requesterId,
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
