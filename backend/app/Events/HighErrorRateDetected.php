<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when error rate exceeds threshold (>5%)
 * 
 * Listeners can integrate with external monitoring systems:
 * - Slack critical alerts
 * - PagerDuty incidents
 * - On-call notifications
 */
class HighErrorRateDetected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public float $errorRate;
    public float $threshold;
    public string $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct(float $errorRate, float $threshold)
    {
        $this->errorRate = $errorRate;
        $this->threshold = $threshold;
        $this->timestamp = now()->toIso8601String();
    }

    /**
     * Get alert data for external systems
     */
    public function toAlertPayload(): array
    {
        return [
            'alert_type' => 'high_error_rate',
            'severity' => $this->errorRate > 10 ? 'critical' : 'warning',
            'error_rate_percent' => $this->errorRate,
            'threshold_percent' => $this->threshold,
            'timestamp' => $this->timestamp,
            'message' => sprintf(
                'High error rate detected: %.2f%% (threshold: %.2f%%)',
                $this->errorRate,
                $this->threshold
            ),
        ];
    }
}
