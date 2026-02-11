<?php

namespace App\Services\Events;

use App\Events\DomainEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RdKafka\Producer;
use RdKafka\Conf;

class EventPublisher
{
    private ?Producer $producer = null;
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = config('events.publishing_enabled', false);
        
        if ($this->enabled && config('events.broker') === 'kafka') {
            $this->initializeKafka();
        }
    }

    /**
     * Initialize Kafka producer
     */
    private function initializeKafka(): void
    {
        try {
            $conf = new Conf();
            $conf->set('metadata.broker.list', config('events.kafka.brokers'));
            $conf->set('compression.codec', 'snappy');
            $conf->set('queue.buffering.max.messages', 100000);
            $conf->set('queue.buffering.max.ms', 100);
            
            $this->producer = new Producer($conf);
        } catch (\Exception $e) {
            Log::error('Failed to initialize Kafka producer', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish domain event
     */
    public function publish(DomainEvent $event): bool
    {
        if (!$this->enabled) {
            Log::debug('Event publishing disabled', [
                'event_type' => $event->eventType,
                'event_id' => $event->eventId,
            ]);
            return false;
        }

        try {
            $topic = $this->getTopicForEvent($event);
            
            if (config('events.broker') === 'kafka') {
                return $this->publishToKafka($topic, $event);
            } elseif (config('events.broker') === 'redis') {
                return $this->publishToRedis($topic, $event);
            }
            
            return false;
        } catch (\Exception $e) {
            Log::error('Failed to publish event', [
                'event_type' => $event->eventType,
                'event_id' => $event->eventId,
                'error' => $e->getMessage(),
            ]);
            
            // Store in dead letter queue
            $this->storeInDeadLetterQueue($event, $e);
            
            return false;
        }
    }

    /**
     * Publish to Kafka
     */
    private function publishToKafka(string $topic, DomainEvent $event): bool
    {
        if (!$this->producer) {
            throw new \RuntimeException('Kafka producer not initialized');
        }

        $kafkaTopic = $this->producer->newTopic($topic);
        
        $kafkaTopic->produce(
            RD_KAFKA_PARTITION_UA,
            0,
            $event->toJson(),
            $event->aggregateId  // Use aggregate ID as key for partitioning
        );
        
        // Flush to ensure delivery
        $this->producer->poll(0);
        
        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $this->producer->flush(1000);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }
        
        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            throw new \RuntimeException('Failed to flush Kafka producer');
        }
        
        Log::info('Event published to Kafka', [
            'event_type' => $event->eventType,
            'event_id' => $event->eventId,
            'topic' => $topic,
        ]);
        
        return true;
    }

    /**
     * Publish to Redis (fallback or development)
     */
    private function publishToRedis(string $topic, DomainEvent $event): bool
    {
        Redis::publish($topic, $event->toJson());
        
        Log::info('Event published to Redis', [
            'event_type' => $event->eventType,
            'event_id' => $event->eventId,
            'topic' => $topic,
        ]);
        
        return true;
    }

    /**
     * Get Kafka topic for event
     */
    private function getTopicForEvent(DomainEvent $event): string
    {
        // Extract domain from event type (e.g., "attendance.recorded.v1" -> "attendance")
        $parts = explode('.', $event->eventType);
        $domain = $parts[0] ?? 'default';
        
        return "{$domain}.events";
    }

    /**
     * Store failed event in dead letter queue
     */
    private function storeInDeadLetterQueue(DomainEvent $event, \Exception $exception): void
    {
        try {
            Redis::lpush('events:dead_letter_queue', json_encode([
                'event' => $event->toArray(),
                'error' => $exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ]));
            
            Log::warning('Event stored in dead letter queue', [
                'event_type' => $event->eventType,
                'event_id' => $event->eventId,
            ]);
        } catch (\Exception $e) {
            Log::critical('Failed to store event in dead letter queue', [
                'event_type' => $event->eventType,
                'event_id' => $event->eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish batch of events
     */
    public function publishBatch(array $events): array
    {
        $results = [];
        
        foreach ($events as $event) {
            $results[$event->eventId] = $this->publish($event);
        }
        
        return $results;
    }
}
