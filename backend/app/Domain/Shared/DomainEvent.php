<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

abstract class DomainEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;
    public readonly string $occurredAt;
    public readonly string $eventType;

    public function __construct(
        public readonly ?int $schoolId = null,
        public readonly ?int $actorId = null,
    ) {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = CarbonImmutable::now()->toIso8601String();
        $this->eventType = static::class;
    }

    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => class_basename($this),
            'occurred_at' => $this->occurredAt,
            'school_id' => $this->schoolId,
            'actor_id' => $this->actorId,
        ];
    }
}
