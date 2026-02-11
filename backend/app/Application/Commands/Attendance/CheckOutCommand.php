<?php

declare(strict_types=1);

namespace App\Application\Commands\Attendance;

final readonly class CheckOutCommand
{
    public function __construct(
        public int $attendanceId,
        public ?float $lat,
        public ?float $lng,
        public ?int $recordedBy,
        public ?string $deviceId = null,
    ) {}
}
