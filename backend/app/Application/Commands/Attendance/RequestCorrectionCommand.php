<?php

declare(strict_types=1);

namespace App\Application\Commands\Attendance;

final readonly class RequestCorrectionCommand
{
    public function __construct(
        public int $attendanceId,
        public string $reason,
        public int $requesterId,
    ) {}
}
