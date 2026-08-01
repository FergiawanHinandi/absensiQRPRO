<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class HighAvailabilityMonitorService
{
    /**
     * Monitoring thresholds
     */
    private array $thresholds = [
        'db_replication_lag_warning' => 10,      // seconds
        'db_replication_lag_critical' => 60,     // seconds
        'db_latency_warning' => 100,             // ms
        'db_latency_critical' => 500,            // ms
        'redis_memory_warning' => 80,            // percentage
        'redis_memory_critical' => 95,           // percentage
        'queue_backlog_warning' => 100,          // jobs
        'queue_backlog_critical' => 1000,        // jobs
        'queue_failed_warning' => 10,            // jobs
        'queue_failed_critical' => 50,           // jobs
        'app_response_warning' => 500,           // ms
        'app_response_critical' => 2000,         // ms
    ];

    private array $webhooks = [];

    public function __construct()
    {
        // Override thresholds from config
        $this->thresholds = array_merge(
            $this->thresholds,
            config('ha-monitoring.thresholds', [])
        );

        $this->webhooks = [
            'slack' => config('services.slack.security_webhook_url'),
            'discord' => config('services.dr_notifications.discord_webhook'),
            'pagerduty' => config('services.pagerduty.webhook'),
        ];
    }

    /**
     * Run all health checks and return comprehensive status
     */
    public function runAllChecks(): array
    {
        $results = [
            'timestamp' => now()->toIso8601String(),
            'overall_status' => 'healthy',
            'components' => [
                'app_server' => $this->checkAppServer(),
                'database' => $this->checkDatabase(),
                'redis' => $this->checkRedis(),
                'queue' => $this->checkQueue(),
                'storage' => $this->checkStorage(),
            ],
            'alerts' => [],
        ];

        // Determine overall status and collect alerts
        foreach ($results['components'] as $component => $status) {
            if ($status['status'] === 'critical') {
                $results['overall_status'] = 'critical';
                $results['alerts'][] = [
                    'component' => $component,
                    'severity' => 'critical',
                    'message' => $status['message'] ?? "{$component} is in critical state",
                ];
            } elseif ($status['status'] === 'warning' && $results['overall_status'] !== 'critical') {
                $results['overall_status'] = 'warning';
                $results['alerts'][] = [
                    'component' => $component,
                    'severity' => 'warning',
                    'message' => $status['message'] ?? "{$component} has warnings",
                ];
            }
        }

        // Cache results
        try {
            Cache::put('ha:monitor:status', $results, now()->addMinutes(5));
        } catch (\Exception $e) {
            // Continue without caching
        }

        return $results;
    }

    /**
     * Check App Server health
     */
    public function checkAppServer(): array
    {
        $result = [
            'status' => 'healthy',
            'checks' => [],
            'message' => null,
        ];

        // 1. Check PHP-FPM / process health
        try {
            $start = microtime(true);
            // Simple self-check
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            $latency = round((microtime(true) - $start) * 1000, 2);

            $result['checks']['self_check'] = [
                'healthy' => true,
                'latency_ms' => $latency,
                'memory_mb' => round($memoryUsage, 2),
            ];

            // Check if memory is too high (> 256MB per process is concerning)
            if ($memoryUsage > 256) {
                $result['status'] = 'warning';
                $result['message'] = "High memory usage: {$memoryUsage}MB";
            }
        } catch (\Exception $e) {
            $result['checks']['self_check'] = [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
            $result['status'] = 'critical';
        }

        // 2. Check opcache status (if enabled)
        if (function_exists('opcache_get_status')) {
            $opcache = opcache_get_status(false);
            if ($opcache) {
                $hitRate = $opcache['opcache_statistics']['hits'] / 
                    max(1, $opcache['opcache_statistics']['hits'] + $opcache['opcache_statistics']['misses']) * 100;
                
                $result['checks']['opcache'] = [
                    'enabled' => true,
                    'hit_rate' => round($hitRate, 2),
                    'memory_used_mb' => round($opcache['memory_usage']['used_memory'] / 1024 / 1024, 2),
                ];
            }
        }

        // 3. Check disk space
        $diskFree = disk_free_space(storage_path());
        $diskTotal = disk_total_space(storage_path());
        $diskUsedPercent = 100 - ($diskFree / $diskTotal * 100);

        $result['checks']['disk'] = [
            'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
            'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
            'used_percent' => round($diskUsedPercent, 2),
        ];

        if ($diskUsedPercent > 90) {
            $result['status'] = 'critical';
            $result['message'] = "Disk usage critical: {$diskUsedPercent}%";
        } elseif ($diskUsedPercent > 80) {
            $result['status'] = $result['status'] === 'critical' ? 'critical' : 'warning';
            $result['message'] = $result['message'] ?? "Disk usage warning: {$diskUsedPercent}%";
        }

        return $result;
    }

    /**
     * Check Database health (Primary + Replica)
     */
    public function checkDatabase(): array
    {
        $result = [
            'status' => 'healthy',
            'checks' => [],
            'message' => null,
            'failover_action' => null,
        ];

        // 1. Check Primary
        try {
            $start = microtime(true);
            DB::connection('pgsql')->select('SELECT 1');
            $primaryLatency = round((microtime(true) - $start) * 1000, 2);

            $result['checks']['primary'] = [
                'healthy' => true,
                'latency_ms' => $primaryLatency,
            ];

            if ($primaryLatency > $this->thresholds['db_latency_critical']) {
                $result['status'] = 'critical';
                $result['message'] = "Primary DB latency critical: {$primaryLatency}ms";
            } elseif ($primaryLatency > $this->thresholds['db_latency_warning']) {
                $result['status'] = 'warning';
                $result['message'] = "Primary DB latency warning: {$primaryLatency}ms";
            }
        } catch (\Exception $e) {
            $result['checks']['primary'] = [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
            $result['status'] = 'critical';
            $result['message'] = 'Primary DB down: ' . $e->getMessage();
            $result['failover_action'] = 'promote_replica';
        }

        // 2. Check Replica (if enabled)
        if (env('DB_REPLICA_ENABLED', false)) {
            try {
                $replicaHost = env('DB_REPLICA_HOST');
                $replicaPort = env('DB_REPLICA_PORT', 5432);

                $start = microtime(true);
                $pdo = new \PDO(
                    "pgsql:host={$replicaHost};port={$replicaPort};dbname=" . env('DB_DATABASE'),
                    env('DB_USERNAME'),
                    env('DB_PASSWORD'),
                    [\PDO::ATTR_TIMEOUT => 5]
                );
                $pdo->query('SELECT 1');
                $replicaLatency = round((microtime(true) - $start) * 1000, 2);

                $result['checks']['replica'] = [
                    'healthy' => true,
                    'latency_ms' => $replicaLatency,
                    'host' => $replicaHost,
                ];
            } catch (\Exception $e) {
                $result['checks']['replica'] = [
                    'healthy' => false,
                    'error' => $e->getMessage(),
                ];
                // Replica down is warning, not critical
                if ($result['status'] !== 'critical') {
                    $result['status'] = 'warning';
                }
            }

            // 3. Check Replication Lag
            if ($result['checks']['primary']['healthy'] ?? false) {
                try {
                    $lagResult = DB::connection('pgsql')->selectOne("
                        SELECT EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp())) AS lag_seconds
                        FROM pg_stat_replication LIMIT 1
                    ");

                    $lagSeconds = $lagResult->lag_seconds ?? 0;
                    $result['checks']['replication_lag'] = [
                        'lag_seconds' => round($lagSeconds, 2),
                        'status' => $lagSeconds < $this->thresholds['db_replication_lag_warning'] ? 'acceptable' : 'high',
                    ];

                    if ($lagSeconds > $this->thresholds['db_replication_lag_critical']) {
                        $result['status'] = 'critical';
                        $result['message'] = "Replication lag critical: {$lagSeconds}s";
                    } elseif ($lagSeconds > $this->thresholds['db_replication_lag_warning']) {
                        $result['status'] = $result['status'] === 'critical' ? 'critical' : 'warning';
                        $result['message'] = $result['message'] ?? "Replication lag warning: {$lagSeconds}s";
                    }
                } catch (\Exception $e) {
                    $result['checks']['replication_lag'] = [
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Check Redis health (with Sentinel support)
     */
    public function checkRedis(): array
    {
        $result = [
            'status' => 'healthy',
            'checks' => [],
            'message' => null,
            'failover_action' => null,
        ];

        try {
            $redis = Redis::connection();
            
            // 1. Ping test
            $start = microtime(true);
            $redis->ping();
            $latency = round((microtime(true) - $start) * 1000, 2);

            $result['checks']['connectivity'] = [
                'healthy' => true,
                'latency_ms' => $latency,
            ];

            // 2. Memory usage
            $info = $redis->info('memory');
            $usedMemory = $info['used_memory'] ?? 0;
            $maxMemory = $info['maxmemory'] ?? 0;
            
            if ($maxMemory > 0) {
                $memoryPercent = ($usedMemory / $maxMemory) * 100;
            } else {
                $memoryPercent = 0;
            }

            $result['checks']['memory'] = [
                'used_mb' => round($usedMemory / 1024 / 1024, 2),
                'max_mb' => $maxMemory > 0 ? round($maxMemory / 1024 / 1024, 2) : 'unlimited',
                'used_percent' => round($memoryPercent, 2),
            ];

            if ($memoryPercent > $this->thresholds['redis_memory_critical']) {
                $result['status'] = 'critical';
                $result['message'] = "Redis memory critical: {$memoryPercent}%";
            } elseif ($memoryPercent > $this->thresholds['redis_memory_warning']) {
                $result['status'] = 'warning';
                $result['message'] = "Redis memory warning: {$memoryPercent}%";
            }

            // 3. Role check (master/slave)
            $replicationInfo = $redis->info('replication');
            $role = $replicationInfo['role'] ?? 'unknown';
            $connectedSlaves = $replicationInfo['connected_slaves'] ?? 0;

            $result['checks']['role'] = [
                'role' => $role,
                'connected_slaves' => $connectedSlaves,
            ];

            // If this is a slave but should be master (Sentinel failover happened)
            if ($role === 'slave' && env('REDIS_EXPECTED_ROLE', 'master') === 'master') {
                $result['status'] = 'warning';
                $result['message'] = 'Redis role changed to slave (Sentinel failover?)';
                $result['failover_action'] = 'sentinel_switched';
            }

            // 4. Sentinel status (if using Sentinel)
            if (env('REDIS_REPLICATION') === 'sentinel') {
                $result['checks']['sentinel'] = $this->checkSentinel();
            }

        } catch (\Exception $e) {
            $result['checks']['connectivity'] = [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
            $result['status'] = 'critical';
            $result['message'] = 'Redis connection failed: ' . $e->getMessage();
            $result['failover_action'] = 'check_sentinel';
        }

        return $result;
    }

    /**
     * Check Redis Sentinel status
     */
    private function checkSentinel(): array
    {
        $sentinelHosts = [
            env('REDIS_SENTINEL_1', 'tcp://127.0.0.1:26379'),
            env('REDIS_SENTINEL_2'),
            env('REDIS_SENTINEL_3'),
        ];

        $activeSentinels = 0;
        $masterInfo = null;

        foreach (array_filter($sentinelHosts) as $sentinel) {
            try {
                $parsed = parse_url($sentinel);
                $host = $parsed['host'] ?? '127.0.0.1';
                $port = $parsed['port'] ?? 26379;

                $conn = @fsockopen($host, $port, $errno, $errstr, 2);
                if ($conn) {
                    fclose($conn);
                    $activeSentinels++;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return [
            'active_sentinels' => $activeSentinels,
            'total_sentinels' => count(array_filter($sentinelHosts)),
            'quorum_met' => $activeSentinels >= 2,
        ];
    }

    /**
     * Check Queue health
     */
    public function checkQueue(): array
    {
        $result = [
            'status' => 'healthy',
            'checks' => [],
            'message' => null,
        ];

        try {
            // 1. Queue backlog (pending jobs)
            $connection = config('queue.default');
            $pendingJobs = 0;

            if ($connection === 'redis') {
                try {
                    $redis = Redis::connection();
                    $queues = ['default', 'high', 'low', 'notifications'];
                    
                    foreach ($queues as $queue) {
                        $key = config('queue.connections.redis.queue', 'default');
                        $pendingJobs += $redis->llen("queues:{$queue}");
                    }
                } catch (\Exception $e) {
                    // Redis might not be available
                }
            } elseif ($connection === 'database') {
                $pendingJobs = DB::table('jobs')->count();
            }

            $result['checks']['backlog'] = [
                'pending_jobs' => $pendingJobs,
            ];

            if ($pendingJobs > $this->thresholds['queue_backlog_critical']) {
                $result['status'] = 'critical';
                $result['message'] = "Queue backlog critical: {$pendingJobs} jobs";
            } elseif ($pendingJobs > $this->thresholds['queue_backlog_warning']) {
                $result['status'] = 'warning';
                $result['message'] = "Queue backlog warning: {$pendingJobs} jobs";
            }

            // 2. Failed jobs count
            $failedJobs = DB::table('failed_jobs')->count();
            $recentFailedJobs = DB::table('failed_jobs')
                ->where('failed_at', '>', now()->subHour())
                ->count();

            $result['checks']['failed_jobs'] = [
                'total' => $failedJobs,
                'last_hour' => $recentFailedJobs,
            ];

            if ($recentFailedJobs > $this->thresholds['queue_failed_critical']) {
                $result['status'] = 'critical';
                $result['message'] = "Failed jobs critical: {$recentFailedJobs} in last hour";
            } elseif ($recentFailedJobs > $this->thresholds['queue_failed_warning']) {
                $result['status'] = $result['status'] === 'critical' ? 'critical' : 'warning';
                $result['message'] = $result['message'] ?? "Failed jobs warning: {$recentFailedJobs} in last hour";
            }

            // 3. Check if workers are running (via horizon or supervisor)
            $result['checks']['workers'] = $this->checkQueueWorkers();

        } catch (\Exception $e) {
            $result['status'] = 'warning';
            $result['message'] = 'Could not check queue status: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Check queue workers status
     */
    private function checkQueueWorkers(): array
    {
        // Check Horizon if available
        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            try {
                $status = Cache::get('horizon:status', 'inactive');
                return [
                    'type' => 'horizon',
                    'status' => $status,
                    'healthy' => $status === 'running',
                ];
            } catch (\Exception $e) {
                // Continue to other checks
            }
        }

        // Check for recent job processing (last 5 minutes)
        try {
            $recentProcessed = DB::table('jobs')
                ->where('reserved_at', '>', now()->subMinutes(5)->timestamp)
                ->exists();

            return [
                'type' => 'inference',
                'recently_processed' => $recentProcessed,
                'healthy' => true, // Can't definitively say unhealthy
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'unknown',
                'healthy' => null,
            ];
        }
    }

    /**
     * Check Storage health
     */
    public function checkStorage(): array
    {
        $result = [
            'status' => 'healthy',
            'checks' => [],
            'message' => null,
        ];

        // 1. Local storage
        try {
            $testFile = storage_path('app/health-check-' . time() . '.tmp');
            file_put_contents($testFile, 'health-check');
            $content = file_get_contents($testFile);
            unlink($testFile);

            $result['checks']['local'] = [
                'healthy' => $content === 'health-check',
                'writable' => true,
            ];
        } catch (\Exception $e) {
            $result['checks']['local'] = [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
            $result['status'] = 'critical';
            $result['message'] = 'Local storage not writable';
        }

        // 2. S3/Cloud storage (if configured and package installed)
        $s3Key = config('filesystems.disks.s3.key');
        $cloudEnabled = config('ha-monitoring.components.storage.check_cloud', false);
        
        if ($cloudEnabled && $s3Key && class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
            try {
                $disk = \Storage::disk('s3');
                $exists = $disk->exists('health-check.txt');
                
                $result['checks']['cloud'] = [
                    'healthy' => true,
                    'type' => 's3',
                ];
            } catch (\Exception $e) {
                $result['checks']['cloud'] = [
                    'healthy' => false,
                    'error' => $e->getMessage(),
                ];
                // Cloud storage failure is warning if local is ok
                if ($result['status'] !== 'critical') {
                    $result['status'] = 'warning';
                    $result['message'] = 'Cloud storage unavailable';
                }
            }
        } else {
            $result['checks']['cloud'] = [
                'skipped' => true,
                'reason' => 'Cloud storage not configured or package not installed',
            ];
        }

        return $result;
    }

    /**
     * Send alert for critical/warning status
     */
    public function sendAlert(array $status): void
    {
        $severity = $status['overall_status'];
        $alerts = $status['alerts'] ?? [];

        if (empty($alerts)) {
            return;
        }

        $message = $this->formatAlertMessage($status);

        // Log alert
        $logMethod = $severity === 'critical' ? 'critical' : 'warning';
        Log::$logMethod('HA Monitor Alert', $status);

        // Send to webhooks
        foreach ($this->webhooks as $type => $url) {
            if (!$url) continue;

            try {
                match ($type) {
                    'slack' => $this->sendSlackAlert($url, $status),
                    'discord' => $this->sendDiscordAlert($url, $status),
                    'pagerduty' => $this->sendPagerDutyAlert($url, $status),
                    default => null,
                };
            } catch (\Exception $e) {
                Log::error("Failed to send {$type} alert", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Format alert message
     */
    private function formatAlertMessage(array $status): string
    {
        $lines = [
            "🚨 HA ALERT: " . strtoupper($status['overall_status']),
            "Time: " . $status['timestamp'],
            "",
        ];

        foreach ($status['alerts'] as $alert) {
            $icon = $alert['severity'] === 'critical' ? '❌' : '⚠️';
            $lines[] = "{$icon} [{$alert['component']}] {$alert['message']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Send Slack alert
     */
    private function sendSlackAlert(string $webhook, array $status): void
    {
        $color = $status['overall_status'] === 'critical' ? 'danger' : 'warning';

        $fields = [];
        foreach ($status['components'] as $name => $component) {
            $icon = match ($component['status']) {
                'healthy' => '✅',
                'warning' => '⚠️',
                'critical' => '❌',
                default => '❓',
            };
            $fields[] = [
                'title' => ucfirst($name),
                'value' => "{$icon} {$component['status']}" . 
                    ($component['message'] ? "\n{$component['message']}" : ''),
                'short' => true,
            ];
        }

        Http::post($webhook, [
            'attachments' => [
                [
                    'color' => $color,
                    'title' => '🚨 High Availability Alert',
                    'text' => "Overall Status: " . strtoupper($status['overall_status']),
                    'fields' => $fields,
                    'footer' => 'AbsensiQRPro HA Monitor',
                    'ts' => time(),
                ],
            ],
        ]);
    }

    /**
     * Send Discord alert
     */
    private function sendDiscordAlert(string $webhook, array $status): void
    {
        $color = $status['overall_status'] === 'critical' ? 0xFF0000 : 0xFFFF00;

        $fields = [];
        foreach ($status['components'] as $name => $component) {
            $fields[] = [
                'name' => ucfirst($name),
                'value' => $component['status'] . ($component['message'] ? ": {$component['message']}" : ''),
                'inline' => true,
            ];
        }

        Http::post($webhook, [
            'embeds' => [
                [
                    'title' => '🚨 High Availability Alert',
                    'description' => "Overall Status: " . strtoupper($status['overall_status']),
                    'color' => $color,
                    'fields' => $fields,
                    'timestamp' => $status['timestamp'],
                ],
            ],
        ]);
    }

    /**
     * Send PagerDuty alert
     */
    private function sendPagerDutyAlert(string $integrationKey, array $status): void
    {
        Http::post('https://events.pagerduty.com/v2/enqueue', [
            'routing_key' => $integrationKey,
            'event_action' => 'trigger',
            'payload' => [
                'summary' => 'HA Alert: ' . strtoupper($status['overall_status']),
                'source' => 'AbsensiQRPro',
                'severity' => $status['overall_status'] === 'critical' ? 'critical' : 'warning',
                'custom_details' => $status,
            ],
        ]);
    }

    /**
     * Execute auto-failover for a component
     */
    public function executeFailover(string $component, string $action): array
    {
        Log::warning("Executing failover", ['component' => $component, 'action' => $action]);

        return match ($component) {
            'database' => $this->executeDatabaseFailover($action),
            'redis' => $this->executeRedisFailover($action),
            default => ['success' => false, 'message' => "Unknown component: {$component}"],
        };
    }

    /**
     * Execute database failover
     */
    private function executeDatabaseFailover(string $action): array
    {
        if ($action === 'promote_replica') {
            // Call the existing failover command
            try {
                \Artisan::call('db:failover', ['action' => 'promote', '--force' => true]);
                return [
                    'success' => true,
                    'message' => 'Replica promoted to primary',
                    'output' => \Artisan::output(),
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Failover failed: ' . $e->getMessage(),
                ];
            }
        }

        return ['success' => false, 'message' => "Unknown action: {$action}"];
    }

    /**
     * Execute Redis failover (Sentinel handles this automatically)
     */
    private function executeRedisFailover(string $action): array
    {
        // Sentinel handles failover automatically
        // This method is for manual intervention if needed
        return [
            'success' => true,
            'message' => 'Redis Sentinel handles failover automatically. Check sentinel status.',
        ];
    }

    /**
     * Get failover history from cache/logs.
     * Returns recent failover events for monitoring and reporting.
     */
    public function getFailoverHistory(int $limit = 50): array
    {
        try {
            // Try to get from cache first
            $history = cache()->get('ha_failover_history', []);
            
            // Limit the results
            return array_slice($history, 0, $limit);
        } catch (\Exception $e) {
            Log::error('Failed to get failover history', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Record a failover event to history.
     * Called internally when failover is executed.
     */
    private function recordFailoverEvent(string $component, string $action, array $result): void
    {
        try {
            $event = [
                'component' => $component,
                'action' => $action,
                'result' => $result,
                'timestamp' => now()->toIso8601String(),
                'duration' => $result['duration'] ?? null,
            ];

            // Get existing history
            $history = cache()->get('ha_failover_history', []);
            
            // Add new event to the beginning
            array_unshift($history, $event);
            
            // Keep only last 100 events
            $history = array_slice($history, 0, 100);
            
            // Store back to cache (30 days retention)
            cache()->put('ha_failover_history', $history, now()->addDays(30));
        } catch (\Exception $e) {
            Log::error('Failed to record failover event', ['error' => $e->getMessage()]);
        }
    }
}
