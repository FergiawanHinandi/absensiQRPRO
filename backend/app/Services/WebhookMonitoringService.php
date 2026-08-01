<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WebhookMonitoringService
{
    /**
     * Track webhook lock acquisition time
     * 
     * @param string $orderId
     * @param float $acquisitionTime Time in milliseconds
     * @return void
     */
    public static function trackLockAcquisition(string $orderId, float $acquisitionTime): void
    {
        if (!config('webhook.enable_monitoring', true)) {
            return;
        }

        Log::info('webhook_lock_acquired', [
            'order_id' => $orderId,
            'acquisition_time_ms' => round($acquisitionTime, 2),
            'timestamp' => now()->toISOString(),
        ]);

        // Store in cache for metrics aggregation
        $key = "webhook_metrics:lock_acquisition:" . now()->format('Y-m-d-H');
        $metrics = Cache::get($key, []);
        $metrics[] = [
            'order_id' => $orderId,
            'time_ms' => $acquisitionTime,
            'timestamp' => now()->timestamp,
        ];
        Cache::put($key, $metrics, 3600); // Keep for 1 hour

        // Alert if lock acquisition took too long (> 5 seconds)
        if ($acquisitionTime > 5000) {
            Log::warning('webhook_lock_acquisition_slow', [
                'order_id' => $orderId,
                'acquisition_time_ms' => round($acquisitionTime, 2),
                'threshold_ms' => 5000,
            ]);
        }
    }

    /**
     * Track webhook lock contention (failed to acquire lock)
     * 
     * @param string $orderId
     * @param string $reason
     * @return void
     */
    public static function trackLockContention(string $orderId, string $reason = 'timeout'): void
    {
        if (!config('webhook.enable_monitoring', true)) {
            return;
        }

        Log::warning('webhook_lock_contention', [
            'order_id' => $orderId,
            'reason' => $reason,
            'timestamp' => now()->toISOString(),
        ]);

        // Increment contention counter
        $key = "webhook_metrics:lock_contention:" . now()->format('Y-m-d-H');
        Cache::increment($key, 1);
        Cache::expire($key, 3600); // Keep for 1 hour
    }

    /**
     * Track webhook processing duration
     * 
     * @param string $orderId
     * @param float $processingTime Time in milliseconds
     * @param string $status
     * @return void
     */
    public static function trackProcessingDuration(string $orderId, float $processingTime, string $status = 'success'): void
    {
        if (!config('webhook.enable_monitoring', true)) {
            return;
        }

        Log::info('webhook_processing_completed', [
            'order_id' => $orderId,
            'processing_time_ms' => round($processingTime, 2),
            'status' => $status,
            'timestamp' => now()->toISOString(),
        ]);

        // Store in cache for metrics aggregation
        $key = "webhook_metrics:processing_duration:" . now()->format('Y-m-d-H');
        $metrics = Cache::get($key, []);
        $metrics[] = [
            'order_id' => $orderId,
            'time_ms' => $processingTime,
            'status' => $status,
            'timestamp' => now()->timestamp,
        ];
        Cache::put($key, $metrics, 3600); // Keep for 1 hour

        // Alert if processing took too long
        $threshold = config('webhook.processing_timeout', 120) * 1000; // Convert to ms
        if ($processingTime > $threshold) {
            Log::warning('webhook_processing_slow', [
                'order_id' => $orderId,
                'processing_time_ms' => round($processingTime, 2),
                'threshold_ms' => $threshold,
                'status' => $status,
            ]);
        }
    }

    /**
     * Track webhook processing start
     * 
     * @param string $orderId
     * @return float Start time in milliseconds
     */
    public static function startProcessing(string $orderId): float
    {
        $startTime = microtime(true) * 1000;

        if (config('webhook.enable_monitoring', true)) {
            Log::info('webhook_processing_started', [
                'order_id' => $orderId,
                'timestamp' => now()->toISOString(),
            ]);
        }

        return $startTime;
    }

    /**
     * Track webhook processing end
     * 
     * @param string $orderId
     * @param float $startTime Start time in milliseconds
     * @param string $status
     * @return void
     */
    public static function endProcessing(string $orderId, float $startTime, string $status = 'success'): void
    {
        $endTime = microtime(true) * 1000;
        $duration = $endTime - $startTime;

        self::trackProcessingDuration($orderId, $duration, $status);
    }

    /**
     * Get webhook metrics for a specific hour
     * 
     * @param string $hour Format: Y-m-d-H
     * @return array
     */
    public static function getMetrics(string $hour): array
    {
        $lockAcquisitions = Cache::get("webhook_metrics:lock_acquisition:{$hour}", []);
        $processingDurations = Cache::get("webhook_metrics:processing_duration:{$hour}", []);
        $lockContentions = Cache::get("webhook_metrics:lock_contention:{$hour}", 0);

        return [
            'hour' => $hour,
            'lock_acquisitions' => [
                'count' => count($lockAcquisitions),
                'avg_time_ms' => count($lockAcquisitions) > 0 
                    ? round(array_sum(array_column($lockAcquisitions, 'time_ms')) / count($lockAcquisitions), 2)
                    : 0,
                'max_time_ms' => count($lockAcquisitions) > 0 
                    ? max(array_column($lockAcquisitions, 'time_ms'))
                    : 0,
            ],
            'processing_durations' => [
                'count' => count($processingDurations),
                'avg_time_ms' => count($processingDurations) > 0 
                    ? round(array_sum(array_column($processingDurations, 'time_ms')) / count($processingDurations), 2)
                    : 0,
                'max_time_ms' => count($processingDurations) > 0 
                    ? max(array_column($processingDurations, 'time_ms'))
                    : 0,
                'success_count' => count(array_filter($processingDurations, fn($m) => $m['status'] === 'success')),
                'failed_count' => count(array_filter($processingDurations, fn($m) => $m['status'] === 'failed')),
            ],
            'lock_contentions' => $lockContentions,
        ];
    }

    /**
     * Get current metrics (last hour)
     * 
     * @return array
     */
    public static function getCurrentMetrics(): array
    {
        return self::getMetrics(now()->format('Y-m-d-H'));
    }
}
