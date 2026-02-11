<?php

declare(strict_types=1);

namespace App\Application\Handlers\QR;

use App\Application\Commands\QR\GenerateQRCommand;
use App\Domain\QR\Events\QRGenerated;
use App\Services\AttendanceService;
use App\Services\TenantContext;

class GenerateQRHandler
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(GenerateQRCommand $command): array
    {
        // Delegate to existing AttendanceService for QR generation
        // This preserves existing HMAC token logic and atomic locking
        $result = $this->attendanceService->generateQR(
            scheduleId: $command->scheduleId,
            teacherId: $command->teacherId,
            type: $command->type,
        );

        // Dispatch domain event
        QRGenerated::dispatch(
            scheduleId: $command->scheduleId,
            qrId: $result['qr_id'] ?? 0,
            type: $command->type,
            expiresAt: $result['expires_at'] ?? now()->addMinutes(5)->toIso8601String(),
            schoolId: $this->tenant->getSchoolIdOrNull() ?? 0,
            actorId: $command->teacherId,
        );

        return $result;
    }
}
