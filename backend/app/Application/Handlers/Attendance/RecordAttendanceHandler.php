<?php

declare(strict_types=1);

namespace App\Application\Handlers\Attendance;

use App\Application\Commands\Attendance\RecordAttendanceCommand;
use App\Application\Guards\SubscriptionPolicyGuard;
use App\Domain\Attendance\Services\AttendanceCheckInDomainService;
use App\Infrastructure\Persistence\AttendanceRepository;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;

class RecordAttendanceHandler
{
    public function __construct(
        private readonly AttendanceCheckInDomainService $checkInService,
        private readonly AttendanceRepository $repository,
        private readonly TenantContext $tenant,
        private readonly SubscriptionPolicyGuard $guard,
    ) {}

    public function handle(RecordAttendanceCommand $command): Attendance
    {
        $schoolId = $this->tenant->getSchoolId();

        // Enforce subscription limits
        $this->guard->ensureCanRecordAttendance($schoolId);

        // Load schedule with validation
        $schedule = Schedule::where('id', $command->scheduleId)
            ->where('school_id', $schoolId)
            ->firstOrFail();

        // Perform check-in through domain service
        $aggregate = $this->checkInService->checkIn(
            studentId: $command->studentId,
            schedule: $schedule,
            schoolId: $schoolId,
            time: CarbonImmutable::now(),
            lat: $command->lat,
            lng: $command->lng,
            recordedBy: $this->tenant->getUserId(),
            source: $command->source,
            requestId: $command->requestId,
        );

        // Persist and dispatch domain events
        return $this->repository->save($aggregate);
    }
}
