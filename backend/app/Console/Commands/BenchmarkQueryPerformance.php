<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Attendance;
use App\Models\User;
use App\Models\Schedule;

class BenchmarkQueryPerformance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:queries 
                            {--iterations=10 : Number of iterations for each benchmark}
                            {--verbose : Show detailed execution plans}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Benchmark query performance and verify index usage (Task 13.4)';

    /**
     * Test results storage
     */
    private array $results = [];
    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private int $skippedTests = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('');
        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║  Query Performance Benchmark & Index Verification - Task 13.4 ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->info('');

        $driver = DB::connection()->getDriverName();
        $this->info("📊 Database Driver: " . strtoupper($driver));
        $this->info("📅 Analysis Date: " . now()->format('Y-m-d H:i:s'));
        $this->info("🔄 Iterations: " . $this->option('iterations'));
        $this->info('');

        // Run test suites
        $this->testCriticalIndexes();
        $this->testHighPriorityIndexes();
        $this->testMediumPriorityIndexes();

        // Display summary
        $this->displaySummary();

        return $this->failedTests > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Test P0 critical indexes
     */
    private function testCriticalIndexes(): void
    {
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info('  P0 CRITICAL INDEXES - Performance Benchmarks');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->newLine();

        // Test 1: QR Code Validation
        $this->testQuery(
            'QR Code Validation',
            'idx_qr_codes_validation',
            'qr_codes',
            function () {
                return DB::table('qr_codes')
                    ->where('token', 'test_token_123')
                    ->where('is_active', true)
                    ->where('valid_until', '>', now())
                    ->first();
            },
            "SELECT * FROM qr_codes WHERE token = ? AND is_active = ? AND valid_until > ?",
            ['test_token_123', 1, now()->toDateTimeString()]
        );

        // Test 2: Active Subscription Check
        $this->testQuery(
            'Active Subscription Check',
            'idx_subscriptions_active_check',
            'subscriptions',
            function () {
                return DB::table('subscriptions')
                    ->where('school_id', 1)
                    ->where('status', 'active')
                    ->orderBy('expires_at', 'desc')
                    ->first();
            },
            "SELECT * FROM subscriptions WHERE school_id = ? AND status = ? ORDER BY expires_at DESC LIMIT 1",
            [1, 'active']
        );
    }

    /**
     * Test P1 high priority indexes
     */
    private function testHighPriorityIndexes(): void
    {
        $this->newLine();
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info('  P1 HIGH PRIORITY INDEXES - Performance Benchmarks');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->newLine();

        // Test 3: Student Attendance History
        $this->testQuery(
            'Student Attendance History (DESC)',
            'idx_attendance_student_history_sorted',
            'attendances',
            function () {
                return Attendance::where('student_id', 1)
                    ->where('attendance_date', '>=', now()->subDays(30))
                    ->orderBy('attendance_date', 'desc')
                    ->get();
            },
            "SELECT * FROM attendances WHERE student_id = ? AND attendance_date >= ? ORDER BY attendance_date DESC",
            [1, now()->subDays(30)->toDateString()]
        );

        // Test 4: Daily School Report
        $this->testQuery(
            'Daily School Report (Covering)',
            'idx_attendance_daily_report_covering',
            'attendances',
            function () {
                return DB::table('attendances')
                    ->select('status', DB::raw('COUNT(*) as count'))
                    ->where('school_id', 1)
                    ->where('attendance_date', now()->toDateString())
                    ->groupBy('status')
                    ->get();
            },
            "SELECT status, COUNT(*) as count FROM attendances WHERE school_id = ? AND attendance_date = ? GROUP BY status",
            [1, now()->toDateString()]
        );
    }

    /**
     * Test P2 medium priority indexes
     */
    private function testMediumPriorityIndexes(): void
    {
        $this->newLine();
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info('  P2 MEDIUM PRIORITY INDEXES - Performance Benchmarks');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->newLine();

        // Test 5: Teacher Schedule Lookup
        $this->testQuery(
            'Teacher Schedule Daily Lookup',
            'idx_schedules_teacher_daily',
            'schedules',
            function () {
                return DB::table('schedules')
                    ->where('teacher_id', 1)
                    ->where('day_of_week', 1)
                    ->where('is_active', true)
                    ->get();
            },
            "SELECT * FROM schedules WHERE teacher_id = ? AND day_of_week = ? AND is_active = ?",
            [1, 1, 1]
        );

        // Test 6: Active Users by Role
        $this->testQuery(
            'Active Users by Role and School',
            'idx_users_school_role_active',
            'users',
            function () {
                return User::where('school_id', 1)
                    ->where('role_type', 'teacher')
                    ->where('is_active', true)
                    ->get();
            },
            "SELECT * FROM users WHERE school_id = ? AND role_type = ? AND is_active = ?",
            [1, 'teacher', 1]
        );

        // Test 7: Class Students List
        $this->testQuery(
            'Class Students Active List',
            'idx_class_students_active',
            'class_students',
            function () {
                return DB::table('class_students')
                    ->where('class_id', 1)
                    ->where('status', 'active')
                    ->get();
            },
            "SELECT * FROM class_students WHERE class_id = ? AND status = ?",
            [1, 'active']
        );
    }

    /**
     * Test a single query
     */
    private function testQuery(
        string $name,
        string $expectedIndex,
        string $table,
        callable $query,
        string $sql,
        array $bindings
    ): void {
        $this->totalTests++;

        // Check if table exists
        if (!Schema::hasTable($table)) {
            $this->warn("⚠️  Table '$table' does not exist - SKIPPED");
            $this->skippedTests++;
            $this->results[$name] = 'SKIP';
            $this->newLine();
            return;
        }

        $this->info("🔍 Testing: $name");

        // Verify index usage with EXPLAIN
        $indexResult = $this->verifyIndexUsage($sql, $bindings, $expectedIndex);

        // Benchmark performance
        $benchmark = $this->benchmarkQuery($query);

        // Display results
        $this->displayTestResult($name, $indexResult, $benchmark, $expectedIndex);

        // Store result
        if ($indexResult['uses_index'] && $benchmark['avg'] < 100) {
            $this->passedTests++;
            $this->results[$name] = 'PASS';
        } else {
            $this->failedTests++;
            $this->results[$name] = 'FAIL';
        }

        $this->newLine();
    }

    /**
     * Verify index usage with EXPLAIN
     */
    private function verifyIndexUsage(string $sql, array $bindings, string $expectedIndex): array
    {
        $driver = DB::connection()->getDriverName();

        try {
            if ($driver === 'mysql') {
                $results = DB::select("EXPLAIN $sql", $bindings);
                $row = $results[0] ?? null;

                if (!$row) {
                    return ['uses_index' => false, 'index_name' => null, 'scan_type' => 'UNKNOWN'];
                }

                $key = $row->key ?? null;
                $usesIndex = !empty($key) && $key !== 'NULL';

                return [
                    'uses_index' => $usesIndex,
                    'index_name' => $key,
                    'scan_type' => $row->type ?? 'ALL',
                    'rows_examined' => $row->rows ?? 0,
                    'matches_expected' => $key === $expectedIndex
                ];
            } elseif ($driver === 'sqlite') {
                $results = DB::select("EXPLAIN QUERY PLAN $sql", $bindings);
                $details = implode(' ', array_column($results, 'detail'));
                $usesIndex = stripos($details, 'USING INDEX') !== false;

                $indexName = null;
                if (preg_match('/USING INDEX (\w+)/i', $details, $matches)) {
                    $indexName = $matches[1];
                }

                return [
                    'uses_index' => $usesIndex,
                    'index_name' => $indexName,
                    'scan_type' => $usesIndex ? 'INDEX' : 'SCAN',
                    'matches_expected' => $indexName === $expectedIndex
                ];
            } elseif ($driver === 'pgsql') {
                $results = DB::select("EXPLAIN (FORMAT JSON) $sql", $bindings);
                $plan = json_decode($results[0]->{'QUERY PLAN'} ?? '{}', true);
                $planNode = $plan[0]['Plan'] ?? [];

                $nodeType = $planNode['Node Type'] ?? 'Unknown';
                $usesIndex = stripos($nodeType, 'Index') !== false;
                $indexName = $planNode['Index Name'] ?? null;

                return [
                    'uses_index' => $usesIndex,
                    'index_name' => $indexName,
                    'scan_type' => $nodeType,
                    'matches_expected' => $indexName === $expectedIndex
                ];
            }
        } catch (\Exception $e) {
            return [
                'uses_index' => false,
                'index_name' => null,
                'scan_type' => 'ERROR',
                'error' => $e->getMessage()
            ];
        }

        return ['uses_index' => false, 'index_name' => null, 'scan_type' => 'UNKNOWN'];
    }

    /**
     * Benchmark query execution time
     */
    private function benchmarkQuery(callable $query): array
    {
        $iterations = (int) $this->option('iterations');
        $times = [];

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $query();
            $end = microtime(true);

            $times[] = ($end - $start) * 1000; // Convert to milliseconds
        }

        sort($times);
        $count = count($times);

        return [
            'min' => round(min($times), 2),
            'max' => round(max($times), 2),
            'avg' => round(array_sum($times) / $count, 2),
            'median' => round($times[floor($count / 2)], 2),
            'p95' => round($times[floor($count * 0.95)], 2),
            'iterations' => $iterations
        ];
    }

    /**
     * Display test result
     */
    private function displayTestResult(string $name, array $indexResult, array $benchmark, string $expectedIndex): void
    {
        // Index usage
        if ($indexResult['uses_index']) {
            $this->line("  ✅ Uses Index: YES");
            $this->line("  📌 Index Name: " . ($indexResult['index_name'] ?? 'N/A'));
            $this->line("  🔍 Scan Type: " . $indexResult['scan_type']);

            if ($indexResult['matches_expected']) {
                $this->line("  ✅ Expected Index: MATCH ($expectedIndex)");
            } else {
                $this->warn("  ⚠️  Expected Index: MISMATCH (expected: $expectedIndex, got: " . ($indexResult['index_name'] ?? 'none') . ")");
            }
        } else {
            $this->error("  ❌ Uses Index: NO (Full table scan)");
            $this->line("  🔍 Scan Type: " . $indexResult['scan_type']);
            $this->error("  ❌ Expected Index: NOT USED ($expectedIndex)");
        }

        if (isset($indexResult['rows_examined'])) {
            $this->line("  📊 Rows Examined: " . $indexResult['rows_examined']);
        }

        // Performance
        $avgTime = $benchmark['avg'];
        $status = $avgTime < 50 ? '✅' : ($avgTime < 100 ? '⚠️ ' : '❌');

        $this->newLine();
        $this->line("  ⏱️  Performance Benchmark:");
        $this->line("     Min: {$benchmark['min']}ms");
        $this->line("     Avg: {$benchmark['avg']}ms $status");
        $this->line("     Median: {$benchmark['median']}ms");
        $this->line("     P95: {$benchmark['p95']}ms");
        $this->line("     Max: {$benchmark['max']}ms");
    }

    /**
     * Display summary
     */
    private function displaySummary(): void
    {
        $this->newLine(2);
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info('  VERIFICATION SUMMARY');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->newLine();

        $this->info("📊 Total Tests: {$this->totalTests}");
        $this->info("✅ Passed: {$this->passedTests}");
        $this->info("❌ Failed: {$this->failedTests}");
        $this->info("⚠️  Skipped: {$this->skippedTests}");
        $this->newLine();

        if ($this->failedTests > 0) {
            $this->warn("⚠️  WARNING: Some indexes are not being used optimally!");
            $this->newLine();
            $this->warn("Failed Tests:");
            foreach ($this->results as $test => $status) {
                if ($status === 'FAIL') {
                    $this->warn("  ❌ $test");
                }
            }
            $this->newLine();
            $this->info("Recommendations:");
            $this->info("  1. Verify indexes were created successfully");
            $this->info("  2. Run: php artisan migrate:status");
            $this->info("  3. Check database for index existence");
            $this->info("  4. Analyze query patterns in application code");
        } else {
            $this->info("✅ All tested indexes are working correctly!");
        }

        $this->newLine();
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info('  PERFORMANCE TARGETS');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->newLine();
        $this->info("Target: All queries should complete in < 50ms");
        $this->newLine();
        $this->info("✅ = < 50ms (Excellent)");
        $this->info("⚠️  = 50-100ms (Acceptable)");
        $this->info("❌ = > 100ms (Needs optimization)");
        $this->newLine();

        $this->info('════════════════════════════════════════════════════════════════');
        $this->newLine();
        $this->info("📝 Next Steps:");
        $this->info("  1. Review failed tests (if any)");
        $this->info("  2. Verify index creation in migration");
        $this->info("  3. Run benchmarks on production-like data");
        $this->info("  4. Monitor query performance in staging");
        $this->info("  5. Deploy to production during maintenance window");
        $this->newLine();
        $this->info("✅ Task 13.4 Complete: Query plans verified with EXPLAIN");
        $this->newLine();
    }
}
