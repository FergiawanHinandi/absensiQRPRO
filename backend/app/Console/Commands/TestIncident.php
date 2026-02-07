<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Models\User;

class TestIncident extends Command
{
    protected $signature = 'test:incident {type : slow_db|scan_fail|queue_flood}';
    protected $description = 'Simulate incident scenarios for observability verification';

    public function handle()
    {
        $type = $this->argument('type');

        $this->info("🔥 Simulating incident: $type");

        switch ($type) {
            case 'slow_db':
                $this->simulateSlowDb();
                break;
            case 'scan_fail':
                $this->simulateScanFail();
                break;
            case 'queue_flood':
                $this->simulateQueueFlood();
                break;
            default:
                $this->error("Unknown incident type.");
        }
    }

    private function simulateSlowDb()
    {
        // Simulate a web request context for tracing
        \Illuminate\Support\Facades\Context::add('trace_id', (string) \Illuminate\Support\Str::uuid());

        $this->info("Executing query with sleep(3)...");

        $start = microtime(true);
        try {
            // Postgres syntax for sleep
            DB::select('SELECT pg_sleep(3)');
        } catch (\Exception $e) {
            // Fallback for MySQL/SQLite just in case env differs
            try {
                DB::select('SELECT sleep(3)');
            } catch (\Exception $e2) {
                // SQLite doesn't have sleep easily, PHP sleep
                sleep(3);
                // Force log since DB listener won't catch PHP sleep
                Log::warning('Slow Database Query (Simulated PHP)', [
                    'duration_ms' => 3000,
                    'trace_id' => \Illuminate\Support\Facades\Context::get('trace_id')
                ]);
            }
        }
        $duration = (microtime(true) - $start) * 1000;

        $this->info("Query finished in {$duration}ms");
        $this->info("👉 Check laravel.log for 'Slow Database Query' with trace_id.");
    }

    private function simulateScanFail()
    {
        $this->info("Simulating failed scan via internal logic...");

        // We log directly to security channel to verify it works
        Log::channel('security')->warning('Security Alert: Suspicious Attendance Scan', [
            'user_id' => 1,
            'reason' => 'Location Spoofing Detected',
            'ip' => '192.168.1.666',
            'trace_id' => (string) \Illuminate\Support\Str::uuid()
        ]);

        $this->info("👉 Check storage/logs/security.log (or laravel.log) for 'Security Alert'.");
    }

    private function simulateQueueFlood()
    {
        $count = 2100;
        $this->info("Pushing $count dummy jobs to Redis queue...");

        // We use Redis facade directly to avoid needing a real Job class class overhead for speed
        $queueName = config('queue.connections.redis.queue', 'default');

        // Pipelining for speed
        Redis::pipeline(function ($pipe) use ($count, $queueName) {
            for ($i = 0; $i < $count; $i++) {
                // Approximate payload for a Laravel job
                $payload = json_encode([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'displayName' => 'App\Jobs\DummyJob',
                    'job' => 'Illuminate\Queue\CallQueuedHandler@call',
                    'data' => [],
                ]);
                $pipe->rpush("queues:$queueName", $payload);
            }
        });

        $this->info("Queue flooded.");
        $this->info("👉 Run 'php artisan monitor:system' to see the alert.");
    }
}
