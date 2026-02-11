<?php

declare(strict_types=1);

namespace App\Domain\Subscription\Events;

use App\Domain\Shared\DomainEvent;

final class SubscriptionExtended extends DomainEvent
{
    public function __construct(
        public readonly int $subscriptionId,
        int $schoolId,
        public readonly string $planType,
        public readonly int $extensionDays,
        public readonly string $previousExpiresAt,
        public readonly string $newExpiresAt,
        public readonly string $reason,
        ?int $actorId = null,
    ) {
        parent::__construct(schoolId: $schoolId, actorId: $actorId);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'subscription_id' => $this->subscriptionId,
            'plan_type' => $this->planType,
            'extension_days' => $this->extensionDays,
            'previous_expires_at' => $this->previousExpiresAt,
            'new_expires_at' => $this->newExpiresAt,
            'reason' => $this->reason,
        ]);
    }
}
