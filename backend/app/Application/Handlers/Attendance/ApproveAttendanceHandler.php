<?php

declare(strict_types=1);

namespace App\Application\Handlers\Attendance;

use App\Application\Commands\Attendance\ApproveAttendanceCommand;
use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Infrastructure\Persistence\AttendanceRepository;
use App\Models\Attendance;

class ApproveAttendanceHandler
{
    public function __construct(
        private readonly AttendanceRepository $repository,
    ) {}

    public function handle(ApproveAttendanceCommand $command): Attendance
    {
        $model = Attendance::findOrFail($command->attendanceId);
        $aggregate = AttendanceAggregate::fromModel($model);

        $aggregate->approve(
            approverId: $command->approverId,
            notes: $command->notes,
        );

        return $this->repository->save($aggregate);
    }
}
