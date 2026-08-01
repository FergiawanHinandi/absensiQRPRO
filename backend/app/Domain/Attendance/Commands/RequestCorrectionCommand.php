<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Commands;

use App\Domain\Shared\Command;

/**
 * Request Correction Command
 * 
 * Represents the intent to request a correction for an attendance record.
 * This triggers a workflow where the correction must be approved by an admin.
 * 
 * @package App\Domain\Attendance\Commands
 */
readonly class RequestCorrectionCommand implements Command
{
    public function __construct(
        public int $attendanceId,
        public string $reason,
        public int $requesterId,
    ) {}

    public function getName(): string
    {
        return 'RequestCorrectionCommand';
    }

    /**
     * Validate the command data
     *
     * @throws \InvalidArgumentException
     */
    public function validate(): void
    {
        if ($this->attendanceId <= 0) {
            throw new \InvalidArgumentException('Invalid attendance ID');
        }

        if ($this->requesterId <= 0) {
            throw new \InvalidArgumentException('Invalid requester ID');
        }

        if (trim($this->reason) === '') {
            throw new \InvalidArgumentException('Correction reason must not be empty');
        }
    }

    public function getPayload(): array
    {
        return [
            'attendance_id' => $this->attendanceId,
            'reason' => $this->reason,
            'requester_id' => $this->requesterId,
        ];
    }
}
