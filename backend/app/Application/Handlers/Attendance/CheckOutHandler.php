<?php

declare(strict_types=1);

namespace App\Application\Handlers\Attendance;

use App\Application\Commands\Attendance\CheckOutCommand;
use App\Domain\Attendance\Aggregates\AttendanceAggregate;
use App\Infrastructure\Persistence\AttendanceRepository;
use App\Models\Attendance;
use Carbon\CarbonImmutable;

class CheckOutHandler
{
    public function __construct(
        private readonly AttendanceRepository $repository,
    ) {}

    public function handle(CheckOutCommand $command): Attendance
    {
        $model = Attendance::findOrFail($command->attendanceId);
        $aggregate = AttendanceAggregate::fromModel($model);

        $aggregate->checkOut(
            time: CarbonImmutable::now(),
            lat: $command->lat,
            lng: $command->lng,
            recordedBy: $command->recordedBy,
            deviceId: $command->deviceId,
        );

        return $this->repository->save($aggregate);
    }
}
