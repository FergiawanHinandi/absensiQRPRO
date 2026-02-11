<?php

namespace App\Events;

use Carbon\Carbon;
use Illuminate\Support\Str;

abstract class DomainEvent
{
    public string $eventId;
    public string $eventType;
    public string $eventVersion;
    public Carbon $timestamp;
    public string $traceId;
    public string $correlationId;
    public ?string $causationId;
    public string $aggregateType;
    public string $aggregateId;
    public ?string $schoolId;
    public ?string $tenantId;
    public ?string $userId;
    public array $metadata;
    public array $payload;

    public function __construct(array $payload = [])
    {
        $this->eventId = (string) Str::uuid();
        $this->eventType = $this->getEventType();
        $this->eventVersion = $this->getEventVersion();
        $this->timestamp = now();
        $this->traceId = request()->header('X-Trace-ID') ?? (string) Str::uuid();
        $this->correlationId = request()->header('X-Correlation-ID') ?? $this->traceId;
        $this->causationId = request()->header('X-Causation-ID');
        $this->aggregateType = $this->getAggregateType();
        $this->aggregateId = $payload['aggregate_id'] ?? (string) Str::uuid();
        $this->schoolId = $payload['school_id'] ?? auth()->user()?->school_id;
        $this->tenantId = $payload['tenant_id'] ?? $this->schoolId;
        $this->userId = $payload['user_id'] ?? auth()->id();
        $this->metadata = $this->buildMetadata();
        $this->payload = $payload;
    }

    /**
     * Get event type (e.g., "attendance.recorded.v1")
     */
    abstract protected function getEventType(): string;

    /**
     * Get event version (e.g., "1.0.0")
     */
    protected function getEventVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Get aggregate type (e.g., "Attendance")
     */
    abstract protected function getAggregateType(): string;

    /**
     * Build metadata
     */
    protected function buildMetadata(): array
    {
        return [
            'source' => config('app.name'),
            'environment' => config('app.env'),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'hostname' => gethostname(),
        ];
    }

    /**
     * Convert event to array for serialization
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'event_version' => $this->eventVersion,
            'timestamp' => $this->timestamp->toIso8601String(),
            'trace_id' => $this->traceId,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'school_id' => $this->schoolId,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'metadata' => $this->metadata,
            'payload' => $this->payload,
        ];
    }

    /**
     * Convert event to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Create event from array
     */
    public static function fromArray(array $data): static
    {
        $event = new static($data['payload'] ?? []);
        
        $event->eventId = $data['event_id'];
        $event->eventType = $data['event_type'];
        $event->eventVersion = $data['event_version'];
        $event->timestamp = Carbon::parse($data['timestamp']);
        $event->traceId = $data['trace_id'];
        $event->correlationId = $data['correlation_id'];
        $event->causationId = $data['causation_id'] ?? null;
        $event->aggregateType = $data['aggregate_type'];
        $event->aggregateId = $data['aggregate_id'];
        $event->schoolId = $data['school_id'] ?? null;
        $event->tenantId = $data['tenant_id'] ?? null;
        $event->userId = $data['user_id'] ?? null;
        $event->metadata = $data['metadata'] ?? [];
        
        return $event;
    }
}
