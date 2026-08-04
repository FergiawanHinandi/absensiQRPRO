<?php

declare(strict_types=1);

namespace App\Application\Handlers\Attendance;

use App\Application\Commands\Attendance\RequestCorrectionCommand;
use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Infrastructure\Persistence\AttendanceRepository;
use App\Models\Attendance;

class RequestCorrectionHandler
{
    public function __construct(
        private readonly AttendanceRepository $repository,
    ) {}

    public function handle(RequestCorrectionCommand $command): Attendance
    {
        $model = Attendance::findOrFail($command->attendanceId);
        $aggregate = AttendanceAggregate::fromModel($model);

        $aggregate->requestCorrection(
            reason: $command->reason,
            requesterId: $command->requesterId,
        );

        return $this->repository->save($aggregate);
    }
}
