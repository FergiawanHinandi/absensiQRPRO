<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a request takes longer than the threshold
 * 
 * Listeners can integrate with external monitoring systems:
 * - Slack notifications
 * - PagerDuty
 * - DataDog
 * - Prometheus
 */
class SlowResponseDetected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $endpoint;
    public float $durationMs;
    public int $statusCode;
    public string $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct(string $endpoint, float $durationMs, int $statusCode)
    {
        $this->endpoint = $endpoint;
        $this->durationMs = $durationMs;
        $this->statusCode = $statusCode;
        $this->timestamp = now()->toIso8601String();
    }

    /**
     * Get alert data for external systems
     */
    public function toAlertPayload(): array
    {
        return [
            'alert_type' => 'slow_response',
            'severity' => $this->durationMs > 5000 ? 'critical' : 'warning',
            'endpoint' => $this->endpoint,
            'duration_ms' => $this->durationMs,
            'status_code' => $this->statusCode,
            'timestamp' => $this->timestamp,
            'message' => sprintf(
                'Slow response detected: %s took %.2fms (status %d)',
                $this->endpoint,
                $this->durationMs,
                $this->statusCode
            ),
        ];
    }
}
