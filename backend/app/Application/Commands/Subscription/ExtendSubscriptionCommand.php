<?php

declare(strict_types=1);

namespace App\Application\Commands\Subscription;

final readonly class ExtendSubscriptionCommand
{
    public function __construct(
        public int $schoolId,
        public int $extensionDays,
        public string $reason,
        public ?int $actorId = null,
    ) {}
}
