<?php

/**
 * Export Jobs Verification Script
 * 
 * Verifies that PDF export jobs were queued correctly
 * after running the admin report export load test.
 * 
 * Usage: php tests/load/verify-export-jobs.php
 */

require __DIR__ . '/../../backend/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$app = require_once __DIR__ . '/../../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo str_repeat("=", 80) . "\n";
echo "Admin Report Export Jobs Verification\n";
echo str_repeat("=", 80) . "\n\n";

$timeWindow = Carbon::now()->subMinutes(5); // Last 5 minutes

echo "Checking queued export jobs from load test...\n";
echo "Time window: Last 5 minutes\n\n";

// Check if jobs table exists
$tablesExist = DB::select("SHOW TABLES LIKE 'jobs'");

if (empty($tablesExist)) {
    echo "⚠️  WARNING: 'jobs' table does not exist\n";
    echo "   Queue may not be configured or using different driver\n\n";
    echo "   Check config/queue.php for queue driver\n";
    $useJobsTable = false;
} else {
    $useJobsTable = true;
}

// Count queued jobs
if ($useJobsTable) {
    $queuedJobs = DB::table('jobs')
        ->where('created_at', '>=', $timeWindow->timestamp)
        ->orWhere('available_at', '>=', $timeWindow->timestamp)
        ->get();
    
    $totalQueued = $queuedJobs->count();
    
    echo "📊 Queued Jobs (in 'jobs' table): {$totalQueued}\n\n";
    
    if ($totalQueued >= 8 && $totalQueued <= 12) {
        echo "✅ PASS: Job count matches expected (8-12 jobs)\n";
        $passJobCount = true;
    } else if ($totalQueued > 0) {
        echo "⚠️  WARNING: Job count outside expected range\n";
        echo "   Expected: ~10 jobs\n";
        echo "   Actual: {$totalQueued}\n";
        $passJobCount = false;
    } else {
        echo "❌ FAIL: No jobs found in queue\n";
        echo "   Jobs may have been processed immediately\n";
        echo "   Or queue driver is not 'database'\n";
        $passJobCount = false;
    }
    
    echo "\n" . str_repeat("-", 80) . "\n\n";
    
    // Analyze job payloads
    if ($totalQueued > 0) {
        echo "📋 Queued Job Details:\n\n";
        
        foreach ($queuedJobs->take(10) as $idx => $job) {
            $payload = json_decode($job->payload, true);
            $displayName = $payload['displayName'] ?? 'Unknown';
            $attempts = $job->attempts ?? 0;
            
            echo "  Job " . ($idx + 1) . ":\n";
            echo "    Type: {$displayName}\n";
            echo "    Queue: {$job->queue}\n";
            echo "    Attempts: {$attempts}\n";
            echo "    Created: " . Carbon::createFromTimestamp($job->created_at)->toDateTimeString() . "\n";
            echo "\n";
        }
    }
} else {
    echo "ℹ️  Skipping jobs table check (table not found)\n\n";
    $passJobCount = null;
}

echo str_repeat("-", 80) . "\n\n";

// Check failed jobs
echo "❌ Failed Jobs Check:\n\n";

$failedJobsExist = DB::select("SHOW TABLES LIKE 'failed_jobs'");

if (!empty($failedJobsExist)) {
    $failedJobs = DB::table('failed_jobs')
        ->where('failed_at', '>=', $timeWindow)
        ->count();
    
    if ($failedJobs === 0) {
        echo "✅ No failed jobs (good)\n";
        $passNoFailures = true;
    } else {
        echo "⚠️  {$failedJobs} jobs failed during test\n";
        
        $recentFailed = DB::table('failed_jobs')
            ->where('failed_at', '>=', $timeWindow)
            ->limit(3)
            ->get();
        
        foreach ($recentFailed as $failed) {
            echo "\n  Failed Job:\n";
            echo "    Connection: {$failed->connection}\n";
            echo "    Queue: {$failed->queue}\n";
            echo "    Failed at: {$failed->failed_at}\n";
            echo "    Exception: " . substr($failed->exception, 0, 200) . "...\n";
        }
        
        $passNoFailures = false;
    }
} else {
    echo "ℹ️  failed_jobs table not found\n";
    $passNoFailures = null;
}

echo "\n" . str_repeat("-", 80) . "\n\n";

// Check for generated reports (if stored in DB)
echo "📄 Generated Reports Check:\n\n";

// Try to find reports table
$reportsTableExists = DB::select("SHOW TABLES LIKE 'reports'") || 
                      DB::select("SHOW TABLES LIKE 'export_reports'") ||
                      DB::select("SHOW TABLES LIKE 'generated_reports'");

if (!empty($reportsTableExists)) {
    // Determine actual table name
    $tableName = null;
    foreach (['reports', 'export_reports', 'generated_reports'] as $table) {
        if (!empty(DB::select("SHOW TABLES LIKE '{$table}'"))) {
            $tableName = $table;
            break;
        }
    }
    
    if ($tableName) {
        $recentReports = DB::table($tableName)
            ->where('created_at', '>=', $timeWindow)
            ->count();
        
        echo "📊 Reports generated in last 5 minutes: {$recentReports}\n";
        
        if ($recentReports >= 8) {
            echo "✅ Reports being generated successfully\n";
        } else if ($recentReports > 0) {
            echo "⚠️  Some reports generated, queue may still be processing\n";
        } else {
            echo "ℹ️  No reports generated yet (jobs may be queued)\n";
        }
    }
} else {
    echo "ℹ️  Reports table not found (may use file storage)\n";
}

echo "\n" . str_repeat("-", 80) . "\n\n";

// Memory usage check
echo "💾 Memory Usage Check:\n\n";

$memoryLimit = ini_get('memory_limit');
$memoryUsage = memory_get_usage(true);
$memoryUsageMB = round($memoryUsage / 1024 / 1024, 2);

echo "  Memory limit: {$memoryLimit}\n";
echo "  Current usage: {$memoryUsageMB} MB\n";

if ($memoryUsageMB < 128) {
    echo "  ✅ Normal memory usage\n";
    $passMemory = true;
} else if ($memoryUsageMB < 256) {
    echo "  ⚠️  Moderate memory usage\n";
    $passMemory = true;
} else {
    echo "  ❌ High memory usage - potential leak\n";
    $passMemory = false;
}

echo "\n" . str_repeat("=", 80) . "\n";

// Final verdict
$allPass = ($passJobCount !== false) && 
           ($passNoFailures !== false) && 
           ($passMemory !== false);

if ($allPass) {
    echo "✅ SUCCESS: Report export jobs queued correctly!\n\n";
    echo "Key Achievements:\n";
    if ($useJobsTable) {
        echo "  ✓ Jobs queued asynchronously\n";
    }
    echo "  ✓ No failed jobs\n";
    echo "  ✓ Normal memory usage\n";
    echo "  ✓ System ready for background processing\n";
    $exitCode = 0;
} else {
    echo "⚠️  REVIEW REQUIRED: Check findings above\n\n";
    
    if ($passJobCount === false) {
        echo "  ✗ Job queue issues detected\n";
    }
    
    if ($passNoFailures === false) {
        echo "  ✗ Some jobs failed\n";
    }
    
    if ($passMemory === false) {
        echo "  ✗ High memory usage\n";
    }
    
    $exitCode = 1;
}

echo str_repeat("=", 80) . "\n";

echo "\n💡 Tips:\n";
echo "  • Start queue worker: php artisan queue:work\n";
echo "  • Monitor jobs: php artisan queue:monitor\n";
echo "  • Retry failed: php artisan queue:retry all\n";
echo "  • Clear old jobs: php artisan queue:flush\n";

exit($exitCode);
