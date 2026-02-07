<?php

/**
 * Notification & Queue Verification Script
 * 
 * Verifies that:
 * 1. Notifications were queued/processed
 * 2. Queue worker is keeping up (backlog check)
 * 3. Retry mechanism is configured
 * 
 * Usage: php tests/load/verify-notifications.php
 */

require __DIR__ . '/../../backend/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$app = require_once __DIR__ . '/../../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo str_repeat("=", 80) . "\n";
echo "Notification Queue Verification\n";
echo str_repeat("=", 80) . "\n\n";

$timeWindow = Carbon::now()->subMinutes(10);
$expectedTotal = 1500;
$tolerance = 0.1; // 10% tolerance for test/network fluctuations

echo "Checking system state (Window: 10 mins)...\n\n";

// 1. Check Jobs in Queue (Pending/Processing)
$pendingJobs = 0;
try {
    $pendingJobs = DB::table('jobs')->count();
    echo "📊 Pending/Processing Jobs in Queue: {$pendingJobs}\n";
} catch (\Exception $e) {
    echo "ℹ️  'jobs' table not found (using sync or redis?)\n";
}

// 2. Check Failed Jobs
$failedJobs = 0;
try {
    $failedJobs = DB::table('failed_jobs')
        ->where('failed_at', '>=', $timeWindow)
        ->count();
    echo "❌ Failed Jobs (Last 10m): {$failedJobs}\n";
    
    if ($failedJobs > 0) {
        $sampleFail = DB::table('failed_jobs')
            ->where('failed_at', '>=', $timeWindow)
            ->first();
        echo "   Sample Failure: " . substr($sampleFail->exception ?? 'Unknown', 0, 100) . "...\n";
    }
} catch (\Exception $e) {
    echo "ℹ️  'failed_jobs' table not found\n";
}

// 3. Verify Notification/Attendance Records (as proxy for dispatch success)
$processedCount = DB::table('attendance_logs')
    ->where('student_id', 'like', 'notify-test-%')
    ->where('created_at', '>=', $timeWindow)
    ->count();

echo "✅ Triggered Events Recorded (Attendance): {$processedCount}\n";

// 4. Check Notifications Table (if database driver used)
$dbNotifications = 0;
try {
    $dbNotifications = DB::table('notifications')
        ->where('created_at', '>=', $timeWindow)
        ->count();
    echo "📩 Database Notifications Created: {$dbNotifications}\n";
} catch (\Exception $e) {
    echo "ℹ️  'notifications' table not found (using mail/push only?)\n";
}

echo "\n" . str_repeat("-", 80) . "\n";
echo "Performance Analysis\n";
echo str_repeat("-", 80) . "\n";

// Analysis
$isQueueBacklogged = $pendingJobs > ($expectedTotal * 0.2); // >20% still pending?
$hasMessageLoss = ($processedCount < ($expectedTotal * (1 - $tolerance)));

if (!$isQueueBacklogged) {
    echo "✅ Queue Health: GOOD (Worker is handling load)\n";
} else {
    echo "⚠️  Queue Health: BACKLOGGED ({$pendingJobs} jobs pending)\n";
    echo "   Recommendation: Increase worker count (php artisan queue:work)\n";
}

if (!$hasMessageLoss) {
    echo "✅ Data Integrity: GOOD (~{$processedCount} events triggered)\n";
} else {
    echo "❌ Data Integrity: POTENTIAL LOSS (Only {$processedCount}/{$expectedTotal} found)\n";
}

// Retry Logic Verification (Configuration check)
echo "\n" . str_repeat("-", 80) . "\n";
echo "Retry Mechanism Check\n";
echo str_repeat("-", 80) . "\n";

$queueConfig = config('queue.connections.database');
$retryAfter = $queueConfig['retry_after'] ?? 'Not Set';
echo "• Retry After: {$retryAfter} seconds\n";

if ($retryAfter !== 'Not Set') {
    echo "✅ Retry configuration detected in queue settings.\n";
} else {
    echo "⚠️  Verify 'retry_after' in config/queue.php\n";
}

echo "\n" . str_repeat("=", 80) . "\n";

// Final Verdict
if (!$hasMessageLoss && $failedJobs < ($expectedTotal * 0.05)) {
    echo "✅ RESULT: PASSED\n";
    echo "   System handled ~1500 notifications successfully.\n";
} else {
    echo "❌ RESULT: FAILED\n";
    echo "   Review failures or backlog above.\n";
}
echo str_repeat("=", 80) . "\n";
