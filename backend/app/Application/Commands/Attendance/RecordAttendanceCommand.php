<?php

declare(strict_types=1);

namespace App\Application\Commands\Attendance;

final readonly class RecordAttendanceCommand
{
    public function __construct(
        public int $studentId,
        public int $scheduleId,
        public string $qrToken,
        public ?float $lat,
        public ?float $lng,
        public ?string $requestId,
        public string $source = 'student_scan',
    ) {}
}
