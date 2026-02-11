<?php

declare(strict_types=1);

namespace App\Domain\QR\Events;

use App\Domain\Shared\DomainEvent;

final class QRGenerated extends DomainEvent
{
    public function __construct(
        public readonly int $scheduleId,
        public readonly int $qrId,
        public readonly string $type,
        public readonly string $expiresAt,
        int $schoolId,
        ?int $actorId = null,
    ) {
        parent::__construct(schoolId: $schoolId, actorId: $actorId);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'schedule_id' => $this->scheduleId,
            'qr_id' => $this->qrId,
            'type' => $this->type,
            'expires_at' => $this->expiresAt,
        ]);
    }
}
