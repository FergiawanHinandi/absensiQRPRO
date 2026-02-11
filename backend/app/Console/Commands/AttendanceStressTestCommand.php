<?php

namespace App\Console\Commands;

use App\Services\Testing\AttendanceStressTestService;
use Illuminate\Console\Command;

class AttendanceStressTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:stress-test 
                            {--url=http://localhost:8000 : Base URL of the API}
                            {--count=500 : Number of concurrent requests}
                            {--concurrency=50 : Number of parallel requests at once}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate high concurrency attendance scanning to test for deadlocks and duplicates';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceStressTestService $tester)
    {
        $baseUrl = $this->option('url');
        $total = (int) $this->option('count');
        $concurrency = (int) $this->option('concurrency');
        
        $this->info("🚀 Starting Attendance API Stress Test");
        $this->info("Target: {$baseUrl}");
        $this->info("Requests: {$total} | Concurrency: {$concurrency}");

        if (!$this->confirm("This will create {$total} test users and database records. Ensure running in TEST database or local. Continue?")) {
            return;
        }

        // 1. SETUP
        $this->output->write("Creating test data... ");
        $setupStats = $tester->setup($total);
        $this->info("Done (" . round($setupStats['duration'], 2) . "s)");
        $this->info("Schedule ID: {$setupStats['schedule_id']} | Students: {$setupStats['students_count']}");

        // 2. EXECUTE
        $this->info("\n🔥 Firing {$total} requests...");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $results = $tester->execute($baseUrl, $concurrency, function() use ($bar) {
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        // 3. REPORT
        $this->renderReport($results, $total);
    }

    protected function renderReport(array $results, int $total)
    {
        $duration = $results['duration'];
        $rps = $total / max($duration, 1);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Requests', number_format($total)],
                ['Duration', number_format($duration, 2) . 's'],
                ['Throughput (RPS)', number_format($rps, 2)],
                ['Success Count', number_format($results['success'])],
                ['Failure Count', number_format($results['failed'])],
                ['Success Rate', number_format(($results['success'] / max($total, 1)) * 100, 2) . '%'],
            ]
        );

        if (!empty($results['status_codes'])) {
            $this->info("\nStatus Codes:");
            foreach ($results['status_codes'] as $code => $count) {
                $this->line("  {$code}: {$count}");
            }
        }

        if (!empty($results['errors'])) {
            $this->error("\nTop Errors:");
            $i = 0;
            foreach ($results['errors'] as $msg => $count) {
                if ($i++ > 5) break; 
                $this->line("  {$count}x: " . (is_string($msg) ? substr($msg, 0, 100) : json_encode($msg)));
            }
        }

        // INTEGRITY CHECKS
        $this->info("\nChecking Database Integrity...");
        
        $integrity = $results['integrity'];
        $duplicates = $integrity['duplicates'];

        $this->table(
            ['Check', 'Result', 'Status'],
            [
                ['DB Records Created', $integrity['db_records'], $integrity['db_records'] === $results['success'] ? '✅ Match' : '⚠️ Mismatch'],
                ['Distinct Students', $integrity['distinct_students'], 'info'],
                ['Duplicates detected', $duplicates, $duplicates === 0 ? '✅ PASSED' : '❌ FAILED'],
            ]
        );

        if ($duplicates > 0) {
            $this->error("CRITICAL: DUPLICATE RECORDS FOUND!");
        } else {
            $this->info("SUCCESS: No duplicates found.");
        }
    }
}
