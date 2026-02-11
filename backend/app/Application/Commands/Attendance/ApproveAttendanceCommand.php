<?php

declare(strict_types=1);

namespace App\Application\Commands\Attendance;

final readonly class ApproveAttendanceCommand
{
    public function __construct(
        public int $attendanceId,
        public int $approverId,
        public ?string $notes = null,
    ) {}
}
