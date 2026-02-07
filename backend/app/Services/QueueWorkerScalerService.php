<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

/**
 * Queue Worker Auto-Scaling Service
 * 
 * Manages automatic scaling of queue workers based on queue length.
 * 
 * Rules:
 * - >500 pending jobs → add worker
 * - <100 jobs for 10 min → remove worker
 */
class QueueWorkerScalerService
{
    /**
     * Configuration defaults
     */
    protected array $config;

    /**
     * Cache key for tracking low-job periods
     */
    protected const CACHE_KEY_LOW_JOB_START = 'queue_scaler:low_job_start';
    protected const CACHE_KEY_LAST_SCALE_UP = 'queue_scaler:last_scale_up';
    protected const CACHE_KEY_LAST_SCALE_DOWN = 'queue_scaler:last_scale_down';
    protected const CACHE_KEY_WORKER_COUNT = 'queue_scaler:worker_count';
    protected const CACHE_KEY_METRICS_HISTORY = 'queue_scaler:metrics_history';

    public function __construct()
    {
        $this->config = config('queue-scaling', [
            'enabled' => true,
            'scale_up_threshold' => 500,
            'scale_down_threshold' => 100,
            'scale_down_duration' => 600, // 10 minutes
            'min_workers' => 1,
            'max_workers' => 10,
            'cooldown_up' => 60,    // 1 minute between scale ups
            'cooldown_down' => 300, // 5 minutes between scale downs
            'workers_to_add' => 1,
            'workers_to_remove' => 1,
            'queues' => ['default', 'high', 'low', 'notifications'],
        ]);
    }

    /**
     * Main evaluation loop - call this periodically
     */
    public function evaluate(): array
    {
        $result = [
            'timestamp' => now()->toISOString(),
            'action' => 'none',
            'reason' => null,
            'metrics' => [],
            'workers' => [],
        ];

        if (!$this->isEnabled()) {
            $result['reason'] = 'Queue worker scaling is disabled';
            return $result;
        }

        // Gather metrics
        $metrics = $this->gatherMetrics();
        $result['metrics'] = $metrics;

        // Get current worker count
        $currentWorkers = $this->getCurrentWorkerCount();
        $result['workers'] = [
            'current' => $currentWorkers,
            'min' => $this->config['min_workers'],
            'max' => $this->config['max_workers'],
        ];

        // Evaluate scaling decision
        $decision = $this->evaluateScaling($metrics, $currentWorkers);
        $result['action'] = $decision['action'];
        $result['reason'] = $decision['reason'];

        // Execute scaling if needed
        if ($decision['action'] !== 'none') {
            $executed = $this->executeScaling($decision['action'], $currentWorkers);
            $result['executed'] = $executed;
            
            if ($executed) {
                $this->logScalingEvent($decision, $metrics, $currentWorkers);
            }
        }

        // Store metrics history
        $this->storeMetricsHistory($metrics);

        return $result;
    }

    /**
     * Gather queue metrics
     */
    public function gatherMetrics(): array
    {
        $metrics = [
            'total_pending' => 0,
            'by_queue' => [],
            'failed_jobs' => 0,
            'processed_last_minute' => 0,
            'average_wait_time' => 0,
        ];

        $connection = config('queue.default');

        // Get pending jobs per queue
        if ($connection === 'redis') {
            $metrics = array_merge($metrics, $this->getRedisQueueMetrics());
        } elseif ($connection === 'database') {
            $metrics = array_merge($metrics, $this->getDatabaseQueueMetrics());
        }

        // Failed jobs
        $metrics['failed_jobs'] = DB::table('failed_jobs')->count();

        return $metrics;
    }

    /**
     * Get metrics from Redis queues
     */
    protected function getRedisQueueMetrics(): array
    {
        $metrics = [
            'total_pending' => 0,
            'by_queue' => [],
        ];

        try {
            $redis = Redis::connection();
            $queues = $this->config['queues'];

            foreach ($queues as $queue) {
                $pending = (int) $redis->llen("queues:{$queue}");
                $delayed = (int) $redis->zcard("queues:{$queue}:delayed");
                $reserved = (int) $redis->zcard("queues:{$queue}:reserved");

                $metrics['by_queue'][$queue] = [
                    'pending' => $pending,
                    'delayed' => $delayed,
                    'reserved' => $reserved,
                    'total' => $pending + $delayed,
                ];

                $metrics['total_pending'] += $pending + $delayed;
            }
        } catch (\Exception $e) {
            Log::warning('Queue scaler: Failed to get Redis metrics', [
                'error' => $e->getMessage(),
            ]);
        }

        return $metrics;
    }

    /**
     * Get metrics from database queues
     */
    protected function getDatabaseQueueMetrics(): array
    {
        $metrics = [
            'total_pending' => 0,
            'by_queue' => [],
        ];

        try {
            $queues = $this->config['queues'];

            foreach ($queues as $queue) {
                $pending = DB::table('jobs')
                    ->where('queue', $queue)
                    ->where('available_at', '<=', now()->timestamp)
                    ->count();

                $delayed = DB::table('jobs')
                    ->where('queue', $queue)
                    ->where('available_at', '>', now()->timestamp)
                    ->count();

                $reserved = DB::table('jobs')
                    ->where('queue', $queue)
                    ->whereNotNull('reserved_at')
                    ->count();

                $metrics['by_queue'][$queue] = [
                    'pending' => $pending,
                    'delayed' => $delayed,
                    'reserved' => $reserved,
                    'total' => $pending + $delayed,
                ];

                $metrics['total_pending'] += $pending + $delayed;
            }
        } catch (\Exception $e) {
            Log::warning('Queue scaler: Failed to get database metrics', [
                'error' => $e->getMessage(),
            ]);
        }

        return $metrics;
    }

    /**
     * Evaluate if scaling is needed
     */
    protected function evaluateScaling(array $metrics, int $currentWorkers): array
    {
        $pending = $metrics['total_pending'];
        $scaleUpThreshold = $this->config['scale_up_threshold'];
        $scaleDownThreshold = $this->config['scale_down_threshold'];
        $scaleDownDuration = $this->config['scale_down_duration'];

        // Check scale UP condition: >500 pending jobs
        if ($pending > $scaleUpThreshold) {
            // Clear low-job tracking
            $this->clearLowJobTracking();

            // Check if we can scale up
            if ($currentWorkers >= $this->config['max_workers']) {
                return [
                    'action' => 'none',
                    'reason' => "At maximum workers ({$currentWorkers}), cannot scale up",
                ];
            }

            // Check cooldown
            if (!$this->canScaleUp()) {
                $remaining = $this->getScaleUpCooldownRemaining();
                return [
                    'action' => 'none',
                    'reason' => "Scale up cooldown active ({$remaining}s remaining)",
                ];
            }

            return [
                'action' => 'scale_up',
                'reason' => "Pending jobs ({$pending}) exceeds threshold ({$scaleUpThreshold})",
                'workers_to_add' => min(
                    $this->config['workers_to_add'],
                    $this->config['max_workers'] - $currentWorkers
                ),
            ];
        }

        // Check scale DOWN condition: <100 jobs for 10 minutes
        if ($pending < $scaleDownThreshold) {
            $lowJobStart = $this->getLowJobTrackingStart();

            if ($lowJobStart === null) {
                // Start tracking low-job period
                $this->startLowJobTracking();
                return [
                    'action' => 'none',
                    'reason' => "Jobs below threshold, starting 10-min observation",
                ];
            }

            $lowJobDuration = now()->timestamp - $lowJobStart;

            if ($lowJobDuration < $scaleDownDuration) {
                $remaining = $scaleDownDuration - $lowJobDuration;
                return [
                    'action' => 'none',
                    'reason' => "Jobs low for {$lowJobDuration}s, need {$remaining}s more",
                ];
            }

            // Low jobs for 10+ minutes - scale down
            if ($currentWorkers <= $this->config['min_workers']) {
                return [
                    'action' => 'none',
                    'reason' => "At minimum workers ({$currentWorkers}), cannot scale down",
                ];
            }

            // Check cooldown
            if (!$this->canScaleDown()) {
                $remaining = $this->getScaleDownCooldownRemaining();
                return [
                    'action' => 'none',
                    'reason' => "Scale down cooldown active ({$remaining}s remaining)",
                ];
            }

            return [
                'action' => 'scale_down',
                'reason' => "Jobs ({$pending}) below threshold for 10+ minutes",
                'workers_to_remove' => min(
                    $this->config['workers_to_remove'],
                    $currentWorkers - $this->config['min_workers']
                ),
            ];
        }

        // Jobs between thresholds - clear tracking and hold steady
        $this->clearLowJobTracking();

        return [
            'action' => 'none',
            'reason' => "Jobs ({$pending}) within normal range",
        ];
    }

    /**
     * Execute scaling action
     */
    protected function executeScaling(string $action, int $currentWorkers): bool
    {
        $provider = config('queue-scaling.provider', 'supervisor');

        try {
            if ($action === 'scale_up') {
                $result = $this->scaleUp($provider, $currentWorkers);
                if ($result) {
                    $this->recordScaleUp();
                }
                return $result;
            }

            if ($action === 'scale_down') {
                $result = $this->scaleDown($provider, $currentWorkers);
                if ($result) {
                    $this->recordScaleDown();
                    $this->clearLowJobTracking();
                }
                return $result;
            }
        } catch (\Exception $e) {
            Log::error('Queue scaler: Failed to execute scaling', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return false;
    }

    /**
     * Scale up workers
     */
    protected function scaleUp(string $provider, int $currentWorkers): bool
    {
        $workersToAdd = $this->config['workers_to_add'];
        $newCount = $currentWorkers + $workersToAdd;

        Log::info("Queue scaler: Scaling UP from {$currentWorkers} to {$newCount} workers");

        return match ($provider) {
            'supervisor' => $this->scaleSupervisor($newCount),
            'kubernetes' => $this->scaleKubernetes($newCount),
            'aws' => $this->scaleAwsEcs($newCount),
            'horizon' => $this->scaleHorizon($newCount),
            default => $this->scaleGeneric($newCount),
        };
    }

    /**
     * Scale down workers
     */
    protected function scaleDown(string $provider, int $currentWorkers): bool
    {
        $workersToRemove = $this->config['workers_to_remove'];
        $newCount = max($this->config['min_workers'], $currentWorkers - $workersToRemove);

        Log::info("Queue scaler: Scaling DOWN from {$currentWorkers} to {$newCount} workers");

        return match ($provider) {
            'supervisor' => $this->scaleSupervisor($newCount),
            'kubernetes' => $this->scaleKubernetes($newCount),
            'aws' => $this->scaleAwsEcs($newCount),
            'horizon' => $this->scaleHorizon($newCount),
            default => $this->scaleGeneric($newCount),
        };
    }

    /**
     * Scale via Supervisor
     */
    protected function scaleSupervisor(int $targetWorkers): bool
    {
        $programName = config('queue-scaling.supervisor.program', 'laravel-worker');
        $numprocs = $targetWorkers;

        // Update supervisor config
        $configPath = config('queue-scaling.supervisor.config_path', '/etc/supervisor/conf.d/laravel-worker.conf');
        
        // Read current config
        if (!file_exists($configPath)) {
            Log::error("Queue scaler: Supervisor config not found at {$configPath}");
            return false;
        }

        $config = file_get_contents($configPath);
        
        // Update numprocs
        $newConfig = preg_replace(
            '/numprocs=\d+/',
            "numprocs={$numprocs}",
            $config
        );

        file_put_contents($configPath, $newConfig);

        // Reload supervisor
        $result = Process::run('supervisorctl reread && supervisorctl update');

        if ($result->successful()) {
            $this->updateWorkerCount($targetWorkers);
            return true;
        }

        Log::error('Queue scaler: Failed to reload supervisor', [
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ]);

        return false;
    }

    /**
     * Scale via Kubernetes HPA/Deployment
     */
    protected function scaleKubernetes(int $targetWorkers): bool
    {
        $namespace = config('queue-scaling.kubernetes.namespace', 'production');
        $deployment = config('queue-scaling.kubernetes.deployment', 'queue-worker');

        $result = Process::run(
            "kubectl scale deployment/{$deployment} --replicas={$targetWorkers} -n {$namespace}"
        );

        if ($result->successful()) {
            $this->updateWorkerCount($targetWorkers);
            return true;
        }

        Log::error('Queue scaler: Failed to scale Kubernetes deployment', [
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ]);

        return false;
    }

    /**
     * Scale via AWS ECS
     */
    protected function scaleAwsEcs(int $targetWorkers): bool
    {
        $cluster = config('queue-scaling.aws.cluster');
        $service = config('queue-scaling.aws.service');
        $region = config('queue-scaling.aws.region', 'ap-southeast-1');

        $result = Process::run(
            "aws ecs update-service --cluster {$cluster} --service {$service} " .
            "--desired-count {$targetWorkers} --region {$region}"
        );

        if ($result->successful()) {
            $this->updateWorkerCount($targetWorkers);
            return true;
        }

        Log::error('Queue scaler: Failed to scale AWS ECS', [
            'output' => $result->output(),
            'error' => $result->errorOutput(),
        ]);

        return false;
    }

    /**
     * Scale via Laravel Horizon
     */
    protected function scaleHorizon(int $targetWorkers): bool
    {
        // Horizon manages its own scaling, but we can adjust configuration
        // This is a placeholder - Horizon has its own auto-scaling
        Log::info('Queue scaler: Horizon manages its own scaling');
        
        // Could trigger horizon:terminate and restart with new config
        $this->updateWorkerCount($targetWorkers);
        return true;
    }

    /**
     * Generic scaling (log-only, for custom implementations)
     */
    protected function scaleGeneric(int $targetWorkers): bool
    {
        Log::info("Queue scaler: Would scale to {$targetWorkers} workers (generic provider)");
        $this->updateWorkerCount($targetWorkers);
        return true;
    }

    /**
     * Get current worker count
     */
    public function getCurrentWorkerCount(): int
    {
        $provider = config('queue-scaling.provider', 'supervisor');

        try {
            return match ($provider) {
                'supervisor' => $this->getSupervisorWorkerCount(),
                'kubernetes' => $this->getKubernetesWorkerCount(),
                'aws' => $this->getAwsEcsWorkerCount(),
                default => $this->getCachedWorkerCount(),
            };
        } catch (\Exception $e) {
            Log::warning('Queue scaler: Failed to get worker count', [
                'error' => $e->getMessage(),
            ]);
            return $this->getCachedWorkerCount();
        }
    }

    /**
     * Get Supervisor worker count
     */
    protected function getSupervisorWorkerCount(): int
    {
        $programName = config('queue-scaling.supervisor.program', 'laravel-worker');
        
        $result = Process::run("supervisorctl status {$programName}:* 2>/dev/null | grep -c RUNNING || echo 0");
        
        if ($result->successful()) {
            return (int) trim($result->output());
        }

        return $this->getCachedWorkerCount();
    }

    /**
     * Get Kubernetes worker count
     */
    protected function getKubernetesWorkerCount(): int
    {
        $namespace = config('queue-scaling.kubernetes.namespace', 'production');
        $deployment = config('queue-scaling.kubernetes.deployment', 'queue-worker');

        $result = Process::run(
            "kubectl get deployment/{$deployment} -n {$namespace} -o jsonpath='{.status.readyReplicas}'"
        );

        if ($result->successful()) {
            return (int) trim($result->output()) ?: $this->config['min_workers'];
        }

        return $this->getCachedWorkerCount();
    }

    /**
     * Get AWS ECS worker count
     */
    protected function getAwsEcsWorkerCount(): int
    {
        $cluster = config('queue-scaling.aws.cluster');
        $service = config('queue-scaling.aws.service');
        $region = config('queue-scaling.aws.region', 'ap-southeast-1');

        $result = Process::run(
            "aws ecs describe-services --cluster {$cluster} --services {$service} " .
            "--region {$region} --query 'services[0].runningCount' --output text"
        );

        if ($result->successful()) {
            return (int) trim($result->output()) ?: $this->config['min_workers'];
        }

        return $this->getCachedWorkerCount();
    }

    /**
     * Cache helpers
     */
    protected function getCachedWorkerCount(): int
    {
        try {
            return (int) Cache::get(self::CACHE_KEY_WORKER_COUNT, $this->config['min_workers']);
        } catch (\Exception $e) {
            return $this->config['min_workers'];
        }
    }

    protected function updateWorkerCount(int $count): void
    {
        try {
            Cache::put(self::CACHE_KEY_WORKER_COUNT, $count, now()->addDay());
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    /**
     * Low-job tracking helpers
     */
    protected function startLowJobTracking(): void
    {
        try {
            Cache::put(self::CACHE_KEY_LOW_JOB_START, now()->timestamp, now()->addHour());
        } catch (\Exception $e) {
            // Ignore
        }
    }

    protected function getLowJobTrackingStart(): ?int
    {
        try {
            return Cache::get(self::CACHE_KEY_LOW_JOB_START);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function clearLowJobTracking(): void
    {
        try {
            Cache::forget(self::CACHE_KEY_LOW_JOB_START);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Cooldown helpers
     */
    protected function canScaleUp(): bool
    {
        try {
            $lastScaleUp = Cache::get(self::CACHE_KEY_LAST_SCALE_UP);
            if ($lastScaleUp === null) {
                return true;
            }
            return (now()->timestamp - $lastScaleUp) >= $this->config['cooldown_up'];
        } catch (\Exception $e) {
            return true;
        }
    }

    protected function canScaleDown(): bool
    {
        try {
            $lastScaleDown = Cache::get(self::CACHE_KEY_LAST_SCALE_DOWN);
            if ($lastScaleDown === null) {
                return true;
            }
            return (now()->timestamp - $lastScaleDown) >= $this->config['cooldown_down'];
        } catch (\Exception $e) {
            return true;
        }
    }

    protected function getScaleUpCooldownRemaining(): int
    {
        try {
            $lastScaleUp = Cache::get(self::CACHE_KEY_LAST_SCALE_UP);
            if ($lastScaleUp === null) {
                return 0;
            }
            return max(0, $this->config['cooldown_up'] - (now()->timestamp - $lastScaleUp));
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function getScaleDownCooldownRemaining(): int
    {
        try {
            $lastScaleDown = Cache::get(self::CACHE_KEY_LAST_SCALE_DOWN);
            if ($lastScaleDown === null) {
                return 0;
            }
            return max(0, $this->config['cooldown_down'] - (now()->timestamp - $lastScaleDown));
        } catch (\Exception $e) {
            return 0;
        }
    }

    protected function recordScaleUp(): void
    {
        try {
            Cache::put(self::CACHE_KEY_LAST_SCALE_UP, now()->timestamp, now()->addDay());
        } catch (\Exception $e) {
            // Ignore
        }
    }

    protected function recordScaleDown(): void
    {
        try {
            Cache::put(self::CACHE_KEY_LAST_SCALE_DOWN, now()->timestamp, now()->addDay());
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Metrics history for trending
     */
    protected function storeMetricsHistory(array $metrics): void
    {
        try {
            $history = Cache::get(self::CACHE_KEY_METRICS_HISTORY, []);
            
            $history[] = [
                'timestamp' => now()->timestamp,
                'pending' => $metrics['total_pending'],
                'failed' => $metrics['failed_jobs'] ?? 0,
            ];

            // Keep last 60 entries (1 hour at 1-minute intervals)
            $history = array_slice($history, -60);

            Cache::put(self::CACHE_KEY_METRICS_HISTORY, $history, now()->addHours(2));
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Log scaling events
     */
    protected function logScalingEvent(array $decision, array $metrics, int $previousWorkers): void
    {
        Log::channel('queue-scaling')->info('Queue worker scaling event', [
            'action' => $decision['action'],
            'reason' => $decision['reason'],
            'previous_workers' => $previousWorkers,
            'pending_jobs' => $metrics['total_pending'],
            'failed_jobs' => $metrics['failed_jobs'] ?? 0,
        ]);
    }

    /**
     * Check if scaling is enabled
     */
    public function isEnabled(): bool
    {
        return config('queue-scaling.enabled', false);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
