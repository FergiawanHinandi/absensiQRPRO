<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;

class AutoScaleQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:autoscale {--os=linux : Operating system (linux/windows)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-scale queue workers based on queue size';

    // Configuration
    const QUEUE_NAME = 'default';
    const SCALE_UP_THRESHOLD = 500;
    const SCALE_DOWN_THRESHOLD = 100;
    const SCALE_DOWN_COOLDOWN_MINUTES = 10;

    // Worker Limits
    const MIN_WORKERS = 1;
    const MAX_WORKERS = 10;
    const POLLING_INTERVAL_SECONDS = 60; // How often this command is run via scheduler

    // Cache Keys
    const KEY_WORKER_COUNT = 'queue:worker_count';
    const KEY_PREFIX_SCALE_DOWN_TIMER = 'queue:scale_down_timer:';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $os = $this->option('os');
        $queueConnection = config('queue.default');

        // 1. Get Loading Status
        $queueLength = $this->getQueueSize($queueConnection);
        $currentWorkers = Cache::get(self::KEY_WORKER_COUNT, self::MIN_WORKERS);

        $this->info("Current Queue Length: {$queueLength}");
        $this->info("Current Workers: {$currentWorkers}");

        // 2. Scale Up Logic
        if ($queueLength > self::SCALE_UP_THRESHOLD) {
            if ($currentWorkers < self::MAX_WORKERS) {
                $this->scaleUp($currentWorkers + 1, $os);

                // Clear any scale down timer since we are high load
                Cache::forget(self::KEY_PREFIX_SCALE_DOWN_TIMER);
            } else {
                $this->warn("Max workers ({self::MAX_WORKERS}) reached. Cannot scale up.");
            }
            return;
        }

        // 3. Scale Down Logic
        if ($queueLength < self::SCALE_DOWN_THRESHOLD) {
            // Check if we are above min workers
            if ($currentWorkers > self::MIN_WORKERS) {

                // Check Timer
                if (!Cache::has(self::KEY_PREFIX_SCALE_DOWN_TIMER)) {
                    // Start timer
                    Cache::put(self::KEY_PREFIX_SCALE_DOWN_TIMER, now(), now()->addMinutes(self::SCALE_DOWN_COOLDOWN_MINUTES + 1));
                    $this->info("Low load detected. Timer started for scale down.");
                } else {
                    $starTime = Cache::get(self::KEY_PREFIX_SCALE_DOWN_TIMER);
                    $minutesPassed = now()->diffInMinutes($starTime);

                    $this->info("Low load duration: {$minutesPassed} minutes.");

                    if ($minutesPassed >= self::SCALE_DOWN_COOLDOWN_MINUTES) {
                        $this->scaleDown($currentWorkers - 1, $os);
                        Cache::forget(self::KEY_PREFIX_SCALE_DOWN_TIMER); // Reset timer after action
                    }
                }
            } else {
                $this->info("At minimum workers ({self::MIN_WORKERS}). No action needed.");
                Cache::forget(self::KEY_PREFIX_SCALE_DOWN_TIMER);
            }
            return;
        }

        // 4. Stability Zone (100-500)
        // Reset scale down timer if we enter "normal" load to be safe? 
        // Or keep it? The prompt says "< 100", so if it goes to 200, it breaks the "for 10 min" rule.
        // Yes, reset timer.
        if (Cache::has(self::KEY_PREFIX_SCALE_DOWN_TIMER)) {
            $this->info("Load normalized (100-500). Resetting scale down timer.");
            Cache::forget(self::KEY_PREFIX_SCALE_DOWN_TIMER);
        }
    }

    private function getQueueSize($connection)
    {
        // Support Redis driver mainly
        if ($connection === 'redis') {
            try {
                // 'queues:default' is the standard redis key for Laravel queues
                // But it depends on prefix. Better use the Queue Facade.
                return \Illuminate\Support\Facades\Queue::size(self::QUEUE_NAME);
            } catch (\Exception $e) {
                $this->error("Redis connection failed: " . $e->getMessage());
                return 0;
            }
        }

        // Fallback for database
        if ($connection === 'database') {
            return \Illuminate\Support\Facades\DB::table(config('queue.connections.database.table'))
                ->where('queue', self::QUEUE_NAME)
                ->count();
        }

        return 0;
    }

    private function scaleUp($targetCount, $os)
    {
        $this->info("🚀 SCALING UP to {$targetCount} workers...");

        if ($os === 'linux') {
            // Standard Supervisor logic: 'supervisorctl start group:process_num'
            // Assumes process_name = laravel-worker_00, 01, etc.
            // 1-indexed for count, 0-indexed for names usually.
            // Let's assume workers are named laravel-worker:laravel-worker_00 to _09

            $processNum = $targetCount - 1; // e.g. target 5 means start index 4
            $processName = sprintf("laravel-worker:laravel-worker_%02d", $processNum);

            // Execute command
            $command = "supervisorctl start {$processName}";
            $this->info("Executing: {$command}");

            // Un-comment to actually run in production
            // Process::run($command);
        } else {
            // Windows / Development simulation
            // On Windows we can't easily background generic processes persistent like Supervisor
            $this->warn("Windows/Dev Mode: Simulating start of worker #{$targetCount}");
        }

        Cache::put(self::KEY_WORKER_COUNT, $targetCount);
    }

    private function scaleDown($targetCount, $os)
    {
        $this->info("🔻 SCALING DOWN to {$targetCount} workers...");

        if ($os === 'linux') {
            // Stop the highest index worker
            // Current was target + 1
            $processNum = $targetCount; // e.g. target 4 means stop index 4 (which was the 5th worker)
            $processName = sprintf("laravel-worker:laravel-worker_%02d", $processNum);

            $command = "supervisorctl stop {$processName}";
            $this->info("Executing: {$command}");

            // Process::run($command);
        } else {
            $this->warn("Windows/Dev Mode: Simulating stop of worker #{$targetCount}");
        }

        Cache::put(self::KEY_WORKER_COUNT, $targetCount);
    }
}
