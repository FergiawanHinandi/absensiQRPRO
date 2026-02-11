<?php

declare(strict_types=1);

namespace App\Application\Commands\QR;

final readonly class GenerateQRCommand
{
    public function __construct(
        public int $scheduleId,
        public int $teacherId,
        public string $type = 'in',
    ) {}
}
