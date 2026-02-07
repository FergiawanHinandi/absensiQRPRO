<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class MonitorSystemHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:system';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor system health metrics (Error rate, Latency, Queue, DB Lag) and alert';

    /**
     * Thresholds
     */
    const THRESHOLD_ERROR_RATE_PERCENT = 2; // > 2%
    const THRESHOLD_LATENCY_MS = 2000;      // > 2s
    const THRESHOLD_QUEUE_SIZE = 2000;      // > 2000
    const THRESHOLD_DB_LAG_BYTES = 52428800; // 50MB (Rough proxy for lag)

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("🔍 Starting System Health Check...");

        // 1. Check API Metrics (from Redis buckets populated by middleware)
        $this->checkApiMetrics();

        // 2. Check Queue Depth
        $this->checkQueueDepth();

        // 3. Check DB Replication Lag
        $this->checkDatabaseLag();

        $this->info("✅ Monitoring checks completed.");
    }

    private function checkApiMetrics()
    {
        // Aggregate last 5 minutes of metrics for stability (window slide)
        // Key format: metrics:http:Hi (Hour Minute)
        // Let's just check current minute and previous minute to be simple
        $currentKey = 'metrics:http:' . now()->format('Hi');
        $prevKey = 'metrics:http:' . now()->subMinute()->format('Hi');

        $totalReq = (int) Redis::get("$currentKey:total") + (int) Redis::get("$prevKey:total");
        $totalErr = (int) Redis::get("$currentKey:errors") + (int) Redis::get("$prevKey:errors");
        $totalSlow = (int) Redis::get("$currentKey:slow") + (int) Redis::get("$prevKey:slow");

        if ($totalReq > 0) {
            $errorRate = ($totalErr / $totalReq) * 100;

            $this->info("API Metrics (2min): Requests: $totalReq, Errors: $totalErr, Slow: $totalSlow");

            // Alert: Error Rate
            if ($errorRate > self::THRESHOLD_ERROR_RATE_PERCENT) {
                $this->sendAlert("🚨 HIGH ERROR RATE DETECTED: " . number_format($errorRate, 2) . "%", 'warning');
            }

            // Alert: Latency Spikes
            // If more than 10% of requests are slow
            if ($totalSlow > 0 && ($totalSlow / $totalReq) > 0.1) {
                $this->sendAlert("🚨 HIGH LATENCY DETECTED: >10% requests slower than 2s", 'critical');
            }
        }
    }

    private function checkQueueDepth()
    {
        $queueName = config('queue.connections.redis.queue', 'default');

        try {
            $size = Redis::llen("queues:$queueName");
            $this->info("Queue Depth ($queueName): $size");

            if ($size > self::THRESHOLD_QUEUE_SIZE) {
                $this->sendAlert("🚨 QUEUE BACKLOG CRITICIAL: $size pending jobs", 'critical');
            }
        } catch (\Exception $e) {
            $this->warn("Could not check queue depth: " . $e->getMessage());
        }
    }

    private function checkDatabaseLag()
    {
        // Only relevant if replication is enabled
        if (config('database.connections.pgsql.read')) {
            // Logic to check PG replication lag
            // DB::connection('pgsql::read')->select('SELECT pg_last_wal_replay_lsn() ...');
            // Simplified placeholder
            $this->info("Checking DB Lag... (Skipped: requires replication setup active)");
        }
    }

    private function sendAlert($message, $level = 'info')
    {
        $this->error($message);

        // Log to specific alert channel (which could pipe to Slack/Email)
        Log::channel('security')->log($level === 'critical' ? 'critical' : 'warning', $message);

        // Simulation of Slack notification
        // Notification::route('slack', '...')->notify(new SystemAlertNotification($message));
    }
}
