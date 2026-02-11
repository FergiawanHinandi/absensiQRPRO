<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Services\Events\EventPublisher;
use Illuminate\Support\Facades\Log;

class PublishDomainEvent
{
    private EventPublisher $publisher;

    public function __construct(EventPublisher $publisher)
    {
        $this->publisher = $publisher;
    }

    /**
     * Handle the event
     */
    public function handle(DomainEvent $event): void
    {
        try {
            $this->publisher->publish($event);
            
            if (config('events.monitoring.log_all_events')) {
                Log::info('Domain event published', [
                    'event_type' => $event->eventType,
                    'event_id' => $event->eventId,
                    'aggregate_id' => $event->aggregateId,
                    'trace_id' => $event->traceId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to publish domain event', [
                'event_type' => $event->eventType,
                'event_id' => $event->eventId,
                'error' => $e->getMessage(),
            ]);
            
            // Don't throw - we don't want to break the main flow
            // Event is stored in dead letter queue by publisher
        }
    }
}
