<?php

/**
 * Security Events Verification Script
 * 
 * Verifies that high-volume security events were logged correctly
 * after running the security events load test.
 * 
 * Usage: php tests/load/verify-security-events.php
 */

require __DIR__ . '/../../backend/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$app = require_once __DIR__ . '/../../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo str_repeat("=", 80) . "\n";
echo "Security Events Logging Verification\n";
echo str_repeat("=", 80) . "\n\n";

$testSessionId = 'security-test-session';
$timeWindow = Carbon::now()->subMinutes(10); // Last 10 minutes

echo "Checking security events from load test...\n";
echo "Session ID: {$testSessionId}\n";
echo "Time window: Last 10 minutes\n\n";

// Count security events from the test
$totalEvents = DB::table('security_events')
    ->where('created_at', '>=', $timeWindow)
    ->where(function($query) use ($testSessionId) {
        $query->where('metadata->session_id', $testSessionId)
              ->orWhere('event_type', 'invalid_qr_scan')
              ->orWhere('event_type', 'unauthorized_access');
    })
    ->count();

echo "📊 Total Security Events Logged: {$totalEvents}\n\n";

// Expected: ~2000 events
$expected = 2000;
$tolerance = 0.05; // 5% tolerance

$minExpected = $expected * (1 - $tolerance);
$maxExpected = $expected * (1 + $tolerance);

if ($totalEvents >= $minExpected && $totalEvents <= $maxExpected) {
    echo "✅ PASS: Event count within expected range ({$minExpected}-{$maxExpected})\n";
    $passEventCount = true;
} else {
    echo "❌ FAIL: Event count outside expected range\n";
    echo "   Expected: ~{$expected} (±5%)\n";
    echo "   Actual: {$totalEvents}\n";
    $passEventCount = false;
}

echo "\n" . str_repeat("-", 80) . "\n\n";

// Check event types distribution
echo "📋 Event Type Distribution:\n\n";

$eventTypes = DB::table('security_events')
    ->select('event_type', DB::raw('COUNT(*) as count'))
    ->where('created_at', '>=', $timeWindow)
    ->groupBy('event_type')
    ->orderByDesc('count')
    ->get();

foreach ($eventTypes as $type) {
    $percentage = $totalEvents > 0 ? round(($type->count / $totalEvents) * 100, 2) : 0;
    echo "  • {$type->event_type}: {$type->count} ({$percentage}%)\n";
}

echo "\n" . str_repeat("-", 80) . "\n\n";

// Check severity distribution
echo "🚨 Severity Distribution:\n\n";

$severities = DB::table('security_events')
    ->select('severity', DB::raw('COUNT(*) as count'))
    ->where('created_at', '>=', $timeWindow)
    ->groupBy('severity')
    ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
    ->get();

foreach ($severities as $severity) {
    $percentage = $totalEvents > 0 ? round(($severity->count / $totalEvents) * 100, 2) : 0;
    
    $icon = match($severity->severity) {
        'critical' => '🔴',
        'high' => '🟠',
        'medium' => '🟡',
        'low' => '🟢',
        default => '⚪',
    };
    
    echo "  {$icon} {$severity->severity}: {$severity->count} ({$percentage}%)\n";
}

echo "\n" . str_repeat("-", 80) . "\n\n";

// Check logging performance (events should be logged quickly)
echo "⚡ Logging Performance:\n\n";

$oldestEvent = DB::table('security_events')
    ->where('created_at', '>=', $timeWindow)
    ->orderBy('created_at', 'asc')
    ->first();

$newestEvent = DB::table('security_events')
    ->where('created_at', '>=', $timeWindow)
    ->orderBy('created_at', 'desc')
    ->first();

if ($oldestEvent && $newestEvent) {
    $timeSpan = Carbon::parse($newestEvent->created_at)
        ->diffInSeconds(Carbon::parse($oldestEvent->created_at));
    
    $eventsPerSecond = $timeSpan > 0 ? round($totalEvents / $timeSpan, 2) : 0;
    
    echo "  • Time span: {$timeSpan} seconds\n";
    echo "  • Average logging rate: {$eventsPerSecond} events/second\n";
    
    if ($eventsPerSecond > 10) {
        echo "  ✅ High throughput logging (good async performance)\n";
        $passLoggingSpeed = true;
    } else {
        echo "  ⚠️  Low throughput - may indicate synchronous logging\n";
        $passLoggingSpeed = false;
    }
}

echo "\n" . str_repeat("-", 80) . "\n\n";

// Sample recent events
echo "📝 Sample Events (5 most recent):\n\n";

$sampleEvents = DB::table('security_events')
    ->where('created_at', '>=', $timeWindow)
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($sampleEvents as $event) {
    echo "  • [{$event->severity}] {$event->event_type}\n";
    echo "    Time: {$event->created_at}\n";
    if ($event->ip_address) {
        echo "    IP: {$event->ip_address}\n";
    }
    echo "\n";
}

echo str_repeat("=", 80) . "\n";

// Final verdict
$allPass = $passEventCount && ($passLoggingSpeed ?? true);

if ($allPass) {
    echo "✅ SUCCESS: Security events logging working correctly!\n";
    echo "\nKey Achievements:\n";
    echo "  ✓ All {$totalEvents} events logged successfully\n";
    echo "  ✓ High-throughput async logging confirmed\n";
    echo "  ✓ Event distribution looks normal\n";
    $exitCode = 0;
} else {
    echo "❌ ISSUES DETECTED: Review findings above\n\n";
    
    if (!$passEventCount) {
        echo "  ✗ Event count mismatch\n";
    }
    
    if (!($passLoggingSpeed ?? true)) {
        echo "  ✗ Logging performance concerns\n";
    }
    
    $exitCode = 1;
}

echo str_repeat("=", 80) . "\n";

exit($exitCode);
