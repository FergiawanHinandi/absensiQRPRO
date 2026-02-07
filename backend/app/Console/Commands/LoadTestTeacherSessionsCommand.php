<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class LoadTestTeacherSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loadtest:teacher-sessions 
                            {--count=50 : Number of teachers to simulate} 
                            {--rounds=5 : Number of rounds to execute}
                            {--interval=10 : Interval between rounds in seconds}
                            {--base-url=http://127.0.0.1:8000 : Base URL of the API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate concurrent teacher traffic for today-sessions endpoint';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = (int) $this->option('count');
        $rounds = (int) $this->option('rounds');
        $interval = (int) $this->option('interval');
        $baseUrl = $this->option('base-url');

        $this->log("Starting Load Test: Teacher Sessions");
        $this->log("Users: {$count}, Rounds: {$rounds}, Base URL: {$baseUrl}");

        // 1. Setup Data
        $teachers = $this->setupData($count);

        if (empty($teachers)) {
            $this->error("Failed to setup teachers. Exiting.");
            return 1;
        }

        // 2. Connectivity Check
        if (! $this->checkConnectivity($baseUrl)) {
            return 1;
        }

        // 3. Execution
        $results = [];
        for ($r = 1; $r <= $rounds; $r++) {
            $this->log("\nRound {$r} of {$rounds}...");
            $roundMetrics = $this->executeRound($teachers, $baseUrl);
            $results[] = $roundMetrics;
            
            $this->displayRoundMetrics($roundMetrics);

            if ($r < $rounds) {
                $this->log("Waiting {$interval}s...");
                sleep($interval);
            }
        }

        // 4. Summary
        $this->displaySummary($results);

        return 0;
    }

    private function log($message)
    {
        $this->info($message);
        file_put_contents(storage_path('logs/loadtest.log'), strip_tags($message) . PHP_EOL, FILE_APPEND);
    }

    private function setupData(int $count): array
    {
        $this->log("Setting up test data...");
        
        try {
            $rid = substr(md5(uniqid('', true)), 0, 6);
            
            // Create School
            $school = School::create([
                'name' => 'LoadTest School ' . $rid,
                'npsn' => (string) rand(10000000, 99999999),
                'school_level' => 'SMA',
                'address' => 'LT Address',
                'is_active' => true,
                'settings' => [],
            ]);

            $teachers = [];
            $passwordHash = Hash::make('password');
            
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            for ($i = 1; $i <= $count; $i++) {
                try {
                    $email = "lt_teacher_{$rid}_{$i}@example.test";
                    $username = "lt_teacher_{$rid}_{$i}";
                    
                    $user = User::create([
                        'school_id' => $school->id,
                        'username' => $username,
                        'name' => "LT Teacher {$rid} {$i}",
                        'email' => $email,
                        'password' => $passwordHash,
                        'role_type' => 'teacher',
                        'is_active' => true,
                        'device_id' => "lt-device-{$rid}-{$i}",
                    ]);

                    $token = $user->createToken('loadtest', ['*'])->plainTextToken;
                    $teachers[] = [
                        'token' => $token,
                        'device_id' => "lt-device-{$rid}-{$i}",
                        'id' => $user->id
                    ];
                } catch (\Throwable $e) {
                    // Ignore dupes or errors, just continue
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();

            $this->info("Created " . count($teachers) . " teachers.");
            return $teachers;

        } catch (\Throwable $e) {
            $this->error("Setup failed: " . $e->getMessage());
            return [];
        }
    }

    private function checkConnectivity(string $baseUrl): bool
    {
        $this->log("Checking connectivity to {$baseUrl}...");
        
        $maxRetries = 5;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            try {
                // Use Http client for connectivity check
                $response = Http::timeout(10)->get($baseUrl);
                $status = $response->status();
                
                if ($status >= 200 && $status < 500) {
                     $this->log("Connectivity OK (Status: {$status})");
                     return true;
                }
                $error = "HTTP $status";
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }

            $this->warn("Attempt {$attempt}/{$maxRetries} failed: " . $error);
            if ($attempt < $maxRetries) {
                sleep(2);
            }
        }

        $this->error("Connectivity Check Failed after {$maxRetries} attempts.");
        $this->warn("Make sure the server is running (php artisan serve --port=...).");
        return false;
    }

    private function executeRound(array $teachers, string $baseUrl): array
    {
        $startTime = microtime(true);
        
        $responses = Http::pool(function ($pool) use ($teachers, $baseUrl) {
            $requests = [];
            foreach ($teachers as $t) {
                $requests[] = $pool->as($t['id'])
                    ->withToken($t['token'])
                    ->withHeaders(['X-Device-ID' => $t['device_id']])
                    ->timeout(10)
                    ->get($baseUrl . '/api/v1/teacher/today-sessions');
            }
            return $requests;
        });

        $duration = microtime(true) - $startTime;
        
        // Collect results
        $metrics = [
            'total' => count($teachers),
            'success' => 0,
            'errors' => 0,
            'avg_time' => 0, 
            'duration' => $duration,
            'status_codes' => [],
        ];

        foreach ($responses as $response) {
            // Http::pool returns array of responses or exceptions?
            // Actually it returns array of Response objects.
            // But if request fails, it might be an instance of Illuminate\Http\Client\Response with error status
            // or if connection failed completely, it might throw exception if not handled.
            // Http::pool catches exceptions and returns them? 
            // In Laravel 9+, pool returns array of responses. Failed requests (network) might be instances of RequestException if configured?
            // Actually standard Http pool returns responses. If network error, $response->ok() is false, $response->status() might be 0 or throw exception on access?
            
            if ($response instanceof \Illuminate\Http\Client\Response) {
                $code = $response->status();
                $metrics['status_codes'][$code] = ($metrics['status_codes'][$code] ?? 0) + 1;
                
                if ($response->successful()) {
                    $metrics['success']++;
                } else {
                    $metrics['errors']++;
                }
            } elseif ($response instanceof \Throwable) {
                $metrics['errors']++;
                // Log exception if needed
            } else {
                 // Fallback
                 $metrics['errors']++;
            }
        }

        return $metrics;
    }

    private function displayRoundMetrics(array $metrics)
    {
        $rps = $metrics['duration'] > 0 ? round($metrics['total'] / $metrics['duration'], 2) : 0;
        $this->log("  Duration: " . round($metrics['duration'], 4) . "s");
        $this->log("  RPS: " . $rps);
        $this->log("  Success: {$metrics['success']}, Errors: {$metrics['errors']}");
        $this->log("  Status Codes: " . json_encode($metrics['status_codes']));
    }

    private function displaySummary(array $results)
    {
        $this->table(
            ['Round', 'Duration (s)', 'Success', 'Errors', 'RPS'],
            collect($results)->map(function ($r, $i) {
                return [
                    $i + 1,
                    round($r['duration'], 4),
                    $r['success'],
                    $r['errors'],
                    $r['duration'] > 0 ? round($r['total'] / $r['duration'], 2) : 0
                ];
            })
        );
    }
}
