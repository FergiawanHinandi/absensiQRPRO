<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use App\Services\Redis\QueueRecoveryService;

/**
 * Queue Service Provider
 * 
 * Registers queue recovery service and sets up event listeners
 * for automatic job recovery during Redis failover scenarios.
 * 
 * Requirements: 3.1, 3.2, 3.3, 3.5
 */
class QueueServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(QueueRecoveryService::class, function ($app) {
            return new QueueRecoveryService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Listen for job processing events to implement idempotency
        Queue::before(function (JobProcessing $event) {
            $this->handleJobProcessing($event);
        });

        // Listen for job completion to release locks
        Queue::after(function (JobProcessed $event) {
            $this->handleJobProcessed($event);
        });

        // Listen for job failures to handle recovery
        Queue::failing(function (JobFailed $event) {
            $this->handleJobFailed($event);
        });

        // Listen for worker stopping to trigger recovery check
        Queue::looping(function () {
            $this->checkQueueHealth();
        });
    }

    /**
     * Handle job processing event
     * 
     * Implements idempotency checks before job execution
     */
    private function handleJobProcessing(JobProcessing $event): void
    {
        try {
            $recoveryService = app(QueueRecoveryService::class);
            
            // Check idempotency for the job
            if (!$recoveryService->ensureIdempotency($event->job)) {
                Log::warning('Duplicate job detected, deleting from queue', [
                    'job_id' => $event->job->getJobId(),
                    'queue' => $event->job->getQueue(),
                ]);
                
                // Delete the duplicate job
                $event->job->delete();
            }
            
        } catch (\Exception $e) {
            Log::error('Error in job processing handler', [
                'job_id' => $event->job->getJobId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle job processed event
     * 
     * Releases idempotency locks after successful job completion
     */
    private function handleJobProcessed(JobProcessed $event): void
    {
        try {
            $recoveryService = app(QueueRecoveryService::class);
            
            // Release the idempotency lock
            $recoveryService->releaseIdempotencyLock($event->job);
            
        } catch (\Exception $e) {
            Log::error('Error in job processed handler', [
                'job_id' => $event->job->getJobId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle job failed event
     * 
     * Logs failure and prepares job for potential recovery
     */
    private function handleJobFailed(JobFailed $event): void
    {
        try {
            Log::error('Job failed', [
                'job_id' => $event->job->getJobId(),
                'queue' => $event->job->getQueue(),
                'exception' => $event->exception->getMessage(),
                'attempts' => $event->job->attempts(),
            ]);
            
            // Release the idempotency lock so job can be retried
            $recoveryService = app(QueueRecoveryService::class);
            $recoveryService->releaseIdempotencyLock($event->job);
            
        } catch (\Exception $e) {
            Log::error('Error in job failed handler', [
                'job_id' => $event->job->getJobId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check queue health periodically
     * 
     * Runs every queue loop iteration to monitor health
     */
    private function checkQueueHealth(): void
    {
        static $lastCheck = 0;
        $checkInterval = 60; // Check every 60 seconds
        
        $now = time();
        
        if ($now - $lastCheck < $checkInterval) {
            return;
        }
        
        $lastCheck = $now;
        
        try {
            $recoveryService = app(QueueRecoveryService::class);
            $health = $recoveryService->monitorQueueHealth();
            
            if ($health['status'] !== 'healthy') {
                Log::warning('Queue health degraded', $health);
                
                // Trigger automatic recovery if issues detected
                if (!empty($health['issues'])) {
                    Log::info('Triggering automatic queue recovery');
                    $recoveryService->recoverFailedJobs();
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error in queue health check', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
