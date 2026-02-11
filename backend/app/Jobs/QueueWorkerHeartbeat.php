<?php

namespace App\Jobs;

use App\Services\ObservabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job that records a heartbeat from queue workers
 * 
 * Dispatched by the scheduler every minute to verify workers are running.
 * The ObservabilityService checks this heartbeat to report worker status.
 */
class QueueWorkerHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 30;

    /**
     * Execute the job.
     */
    public function handle(ObservabilityService $observability): void
    {
        $observability->recordWorkerHeartbeat();
    }
}
