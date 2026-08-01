<?php

namespace App\Services\Redis;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Contracts\Queue\Job;
use Carbon\Carbon;

/**
 * Queue Recovery Service
 * 
 * Handles job recovery mechanisms for interrupted jobs during Redis failover.
 * Implements idempotency checks, retry logic with exponential backoff,
 * and job priority preservation.
 * 
 * Requirements: 3.1, 3.3, 3.5
 */
class QueueRecoveryService
{
    private const JOB_LOCK_PREFIX = 'job_lock:';
    private const JOB_LOCK_TTL = 300; // 5 minutes
    private const MAX_RETRY_ATTEMPTS = 3;
    private const INITIAL_BACKOFF_SECONDS = 10;
    private const MAX_BACKOFF_SECONDS = 300; // 5 minutes
    
    private $redis;
    private $queueConnection;
    
    public function __construct()
    {
        $this->redis = Redis::connection('queue');
        $this->queueConnection = config('queue.default', 'redis');
    }
    
    /**
     * Recover failed jobs after Redis failover
     * 
     * Scans for interrupted jobs and requeues them with appropriate retry logic.
     * Implements idempotency checks to prevent duplicate execution.
     * 
     * @return array Recovery statistics
     */
    public function recoverFailedJobs(): array
    {
        $stats = [
            'total_found' => 0,
            'recovered' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        
        try {
            $failedJobs = $this->getFailedJobs();
            $stats['total_found'] = count($failedJobs);
            
            foreach ($failedJobs as $job) {
                try {
                    if ($this->shouldRetry($job)) {
                        if ($this->requeueJob($job)) {
                            $stats['recovered']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } else {
                        $stats['skipped']++;
                        Log::warning('Job exceeded max retry attempts', [
                            'job_id' => $job['id'] ?? 'unknown',
                            'attempts' => $job['attempts'] ?? 0,
                        ]);
                    }
                } catch (\Exception $e) {
                    $stats['failed']++;
                    Log::error('Failed to recover job', [
                        'job_id' => $job['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            Log::info('Queue recovery completed', $stats);
            
        } catch (\Exception $e) {
            Log::error('Queue recovery failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
        
        return $stats;
    }
    
    /**
     * Ensure job idempotency using Redis locks
     * 
     * Prevents duplicate job execution during failover scenarios by
     * acquiring a distributed lock for the job.
     * 
     * @param mixed $job Job instance or job data
     * @return bool True if lock acquired (job can proceed), false otherwise
     */
    public function ensureIdempotency($job): bool
    {
        $jobId = $this->generateJobId($job);
        $lockKey = self::JOB_LOCK_PREFIX . $jobId;
        
        try {
            // Try to acquire lock with NX (only if not exists) and EX (expiration)
            $acquired = $this->redis->set(
                $lockKey,
                Carbon::now()->toIso8601String(),
                'EX',
                self::JOB_LOCK_TTL,
                'NX'
            );
            
            if ($acquired) {
                Log::debug('Job lock acquired', ['job_id' => $jobId]);
                return true;
            }
            
            Log::warning('Job already processing (lock exists)', [
                'job_id' => $jobId,
                'lock_key' => $lockKey,
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('Failed to acquire job lock', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            
            // On error, allow job to proceed to avoid blocking
            return true;
        }
    }
    
    /**
     * Release job idempotency lock
     * 
     * @param mixed $job Job instance or job data
     * @return bool True if lock released successfully
     */
    public function releaseIdempotencyLock($job): bool
    {
        $jobId = $this->generateJobId($job);
        $lockKey = self::JOB_LOCK_PREFIX . $jobId;
        
        try {
            $deleted = $this->redis->del($lockKey);
            
            if ($deleted) {
                Log::debug('Job lock released', ['job_id' => $jobId]);
            }
            
            return (bool) $deleted;
            
        } catch (\Exception $e) {
            Log::error('Failed to release job lock', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Get failed jobs from Redis
     * 
     * @return array Array of failed job data
     */
    private function getFailedJobs(): array
    {
        $failedJobs = [];
        
        try {
            // Get failed jobs from Laravel's failed_jobs table
            $dbFailedJobs = \DB::table('failed_jobs')
                ->where('connection', 'redis')
                ->whereNull('failed_at')
                ->orWhere('failed_at', '>', Carbon::now()->subHours(24))
                ->get();
            
            foreach ($dbFailedJobs as $failedJob) {
                $payload = json_decode($failedJob->payload, true);
                
                $failedJobs[] = [
                    'id' => $failedJob->uuid,
                    'queue' => $failedJob->queue,
                    'payload' => $payload,
                    'exception' => $failedJob->exception,
                    'failed_at' => $failedJob->failed_at,
                    'attempts' => $this->extractAttempts($payload),
                ];
            }
            
            // Also check for reserved jobs that might be stuck
            $stuckJobs = $this->getStuckReservedJobs();
            $failedJobs = array_merge($failedJobs, $stuckJobs);
            
        } catch (\Exception $e) {
            Log::error('Failed to retrieve failed jobs', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $failedJobs;
    }
    
    /**
     * Get stuck reserved jobs from Redis
     * 
     * Jobs that were reserved but never completed due to failover
     * 
     * @return array Array of stuck job data
     */
    private function getStuckReservedJobs(): array
    {
        $stuckJobs = [];
        
        try {
            $queues = ['high', 'default', 'attendance', 'notifications', 'low'];
            $prefix = config('database.redis.options.prefix', '');
            
            foreach ($queues as $queueName) {
                $reservedKey = $prefix . 'queues:' . $queueName . ':reserved';
                
                // Get all reserved jobs
                $reservedJobs = $this->redis->zrangebyscore(
                    $reservedKey,
                    '-inf',
                    Carbon::now()->subMinutes(5)->timestamp // Stuck for > 5 minutes
                );
                
                foreach ($reservedJobs as $jobData) {
                    $payload = json_decode($jobData, true);
                    
                    if ($payload) {
                        $stuckJobs[] = [
                            'id' => $payload['uuid'] ?? uniqid('stuck_'),
                            'queue' => $queueName,
                            'payload' => $payload,
                            'exception' => 'Job stuck in reserved state (possible failover)',
                            'failed_at' => null,
                            'attempts' => $payload['attempts'] ?? 0,
                        ];
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to retrieve stuck reserved jobs', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $stuckJobs;
    }
    
    /**
     * Check if job should be retried
     * 
     * @param array $job Job data
     * @return bool True if job should be retried
     */
    private function shouldRetry(array $job): bool
    {
        $attempts = $job['attempts'] ?? 0;
        $maxAttempts = $this->getMaxAttempts($job);
        
        return $attempts < $maxAttempts;
    }
    
    /**
     * Requeue job with exponential backoff
     * 
     * @param array $job Job data
     * @return bool True if job was requeued successfully
     */
    private function requeueJob(array $job): bool
    {
        try {
            // Check idempotency before requeueing
            if (!$this->ensureIdempotency($job)) {
                Log::info('Job already processing, skipping requeue', [
                    'job_id' => $job['id'],
                ]);
                return false;
            }
            
            $payload = $job['payload'];
            $attempts = $job['attempts'] ?? 0;
            
            // Calculate backoff delay
            $delay = $this->calculateBackoff($attempts);
            
            // Increment attempts
            if (isset($payload['data'])) {
                $payload['attempts'] = $attempts + 1;
            }
            
            // Push job back to queue with delay
            $queue = $job['queue'] ?? 'default';
            
            if ($delay > 0) {
                Queue::connection($this->queueConnection)
                    ->laterOn($queue, $delay, $this->reconstructJob($payload));
            } else {
                Queue::connection($this->queueConnection)
                    ->pushOn($queue, $this->reconstructJob($payload));
            }
            
            Log::info('Job requeued successfully', [
                'job_id' => $job['id'],
                'queue' => $queue,
                'attempts' => $attempts + 1,
                'delay_seconds' => $delay,
            ]);
            
            // Remove from failed jobs table if it was there
            if (isset($job['id'])) {
                \DB::table('failed_jobs')
                    ->where('uuid', $job['id'])
                    ->delete();
            }
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to requeue job', [
                'job_id' => $job['id'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Calculate exponential backoff delay
     * 
     * @param int $attempts Number of attempts
     * @return int Delay in seconds
     */
    private function calculateBackoff(int $attempts): int
    {
        $delay = min(
            self::MAX_BACKOFF_SECONDS,
            self::INITIAL_BACKOFF_SECONDS * pow(2, $attempts)
        );
        
        return (int) $delay;
    }
    
    /**
     * Generate unique job ID for idempotency
     * 
     * @param mixed $job Job instance or job data
     * @return string Unique job identifier
     */
    private function generateJobId($job): string
    {
        if ($job instanceof Job) {
            return $job->getJobId();
        }
        
        if (is_array($job)) {
            if (isset($job['id'])) {
                return $job['id'];
            }
            
            if (isset($job['uuid'])) {
                return $job['uuid'];
            }
            
            if (isset($job['payload'])) {
                $payload = is_string($job['payload']) 
                    ? json_decode($job['payload'], true) 
                    : $job['payload'];
                
                if (isset($payload['uuid'])) {
                    return $payload['uuid'];
                }
            }
        }
        
        // Fallback: generate hash from job data
        return md5(json_encode($job));
    }
    
    /**
     * Extract attempts count from job payload
     * 
     * @param array $payload Job payload
     * @return int Number of attempts
     */
    private function extractAttempts(array $payload): int
    {
        if (isset($payload['attempts'])) {
            return (int) $payload['attempts'];
        }
        
        if (isset($payload['data']['attempts'])) {
            return (int) $payload['data']['attempts'];
        }
        
        return 0;
    }
    
    /**
     * Get max attempts for job
     * 
     * @param array $job Job data
     * @return int Maximum retry attempts
     */
    private function getMaxAttempts(array $job): int
    {
        $payload = $job['payload'] ?? [];
        
        // Check if job specifies max attempts
        if (isset($payload['maxTries'])) {
            return (int) $payload['maxTries'];
        }
        
        if (isset($payload['data']['maxTries'])) {
            return (int) $payload['data']['maxTries'];
        }
        
        // Use default from config or constant
        return config('queue.max_attempts', self::MAX_RETRY_ATTEMPTS);
    }
    
    /**
     * Reconstruct job from payload
     * 
     * @param array $payload Job payload
     * @return mixed Job instance or payload
     */
    private function reconstructJob(array $payload)
    {
        // If payload has displayName, it's a serialized job
        if (isset($payload['displayName'])) {
            return $payload;
        }
        
        // Otherwise, try to unserialize the job
        if (isset($payload['data']['command'])) {
            return unserialize($payload['data']['command']);
        }
        
        return $payload;
    }
    
    /**
     * Monitor queue health and trigger recovery if needed
     * 
     * @return array Health status
     */
    public function monitorQueueHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'queues' => [],
            'issues' => [],
        ];
        
        try {
            $queues = ['high', 'default', 'attendance', 'notifications', 'low'];
            $prefix = config('database.redis.options.prefix', '');
            
            foreach ($queues as $queueName) {
                $queueKey = $prefix . 'queues:' . $queueName;
                $reservedKey = $prefix . 'queues:' . $queueName . ':reserved';
                
                $pendingCount = $this->redis->llen($queueKey);
                $reservedCount = $this->redis->zcard($reservedKey);
                
                $health['queues'][$queueName] = [
                    'pending' => $pendingCount,
                    'reserved' => $reservedCount,
                ];
                
                // Check for stuck jobs
                $stuckCount = $this->redis->zcount(
                    $reservedKey,
                    '-inf',
                    Carbon::now()->subMinutes(5)->timestamp
                );
                
                if ($stuckCount > 0) {
                    $health['status'] = 'degraded';
                    $health['issues'][] = [
                        'queue' => $queueName,
                        'type' => 'stuck_jobs',
                        'count' => $stuckCount,
                    ];
                }
            }
            
        } catch (\Exception $e) {
            $health['status'] = 'unhealthy';
            $health['error'] = $e->getMessage();
            
            Log::error('Queue health check failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $health;
    }
}
