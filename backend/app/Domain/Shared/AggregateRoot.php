<?php

declare(strict_types=1);

namespace App\Domain\Shared;

abstract class AggregateRoot
{
    /** @var DomainEvent[] */
    private array $pendingEvents = [];

    protected function recordEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /**
     * Release and clear all pending domain events.
     *
     * @return DomainEvent[]
     */
    public function releasePendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    /**
     * Check if there are pending domain events.
     */
    public function hasPendingEvents(): bool
    {
        return count($this->pendingEvents) > 0;
    }
}
