<?php

namespace App\Listeners;

use App\Events\RedisCircuitBreakerClosed;
use App\Events\RedisCircuitBreakerHalfOpen;
use App\Events\RedisCircuitBreakerOpened;
use App\Services\Alerting\SlackAlert;
use Illuminate\Support\Facades\Log;

/**
 * Log Redis Circuit Breaker Events
 * 
 * Logs circuit breaker state transitions for monitoring and alerting
 */
class LogRedisCircuitBreakerEvent
{
    /**
     * Handle Redis circuit breaker opened event
     */
    public function handleOpened(RedisCircuitBreakerOpened $event): void
    {
        Log::warning('redis_fallback_activated', [
            'event' => 'circuit_breaker_opened',
            'message' => 'Redis is unavailable, system is using fallback mechanisms',
            'timestamp' => now()->toIso8601String(),
            'severity' => 'high',
            'action_required' => 'Check Redis server health and connectivity',
        ]);
        
        // Send alert notification
        $this->sendAlert(
            'Redis Circuit Breaker OPEN',
            'Redis is unavailable. System is using database fallback for caching.',
            'critical'
        );
    }
    
    /**
     * Handle Redis circuit breaker half-open event
     */
    public function handleHalfOpen(RedisCircuitBreakerHalfOpen $event): void
    {
        Log::info('redis_recovery_attempt', [
            'event' => 'circuit_breaker_half_open',
            'message' => 'Testing Redis recovery',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
    
    /**
     * Handle Redis circuit breaker closed event
     */
    public function handleClosed(RedisCircuitBreakerClosed $event): void
    {
        Log::info('redis_connection_restored', [
            'event' => 'circuit_breaker_closed',
            'message' => 'Redis connection restored, fallback deactivated',
            'timestamp' => now()->toIso8601String(),
        ]);
        
        // Send recovery notification
        $this->sendAlert(
            'Redis Circuit Breaker CLOSED',
            'Redis connection has been restored. System is back to normal operation.',
            'info'
        );
    }
    
    /**
     * Send alert notification
     * 
     * @param string $title Alert title
     * @param string $message Alert message
     * @param string $severity Severity level (critical, warning, info)
     */
    private function sendAlert(string $title, string $message, string $severity): void
    {
        // Log the alert
        Log::channel('stack')->log(
            $severity === 'critical' ? 'error' : ($severity === 'warning' ? 'warning' : 'info'),
            $title,
            ['message' => $message, 'timestamp' => now()->toIso8601String()]
        );
        
        // Send to Slack based on severity
        match ($severity) {
            'critical' => SlackAlert::critical($title, ['message' => $message]),
            'warning' => SlackAlert::warning($title, ['message' => $message]),
            default => SlackAlert::info($title, ['message' => $message]),
        };
    }
    
    /**
     * Register the listeners for the subscriber
     */
    public function subscribe($events): array
    {
        return [
            RedisCircuitBreakerOpened::class => 'handleOpened',
            RedisCircuitBreakerHalfOpen::class => 'handleHalfOpen',
            RedisCircuitBreakerClosed::class => 'handleClosed',
        ];
    }
}
