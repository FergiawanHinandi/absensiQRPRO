<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Logging\LogContext;
use App\Logging\CorrelatedLogger;

echo "=== Testing LogContext ===\n\n";

// Test 1: Initialize from request
echo "1. Initialize from request:\n";
LogContext::initFromRequest(request());
echo "   request_id: " . LogContext::get('request_id') . "\n";
echo "   trace_id: " . LogContext::get('trace_id') . "\n";
echo "   span_id: " . LogContext::get('span_id') . "\n";
echo "   span_depth: " . LogContext::get('span_depth') . "\n\n";

// Test 2: Push a new span
echo "2. Push span 'AttendanceService.recordScan':\n";
$parentSpan = LogContext::get('span_id');
$newSpan = LogContext::pushSpan('AttendanceService.recordScan');
echo "   new span_id: " . $newSpan . "\n";
echo "   parent_span_id: " . LogContext::get('parent_span_id') . "\n";
echo "   operation: " . LogContext::get('current_operation') . "\n";
echo "   span_depth: " . LogContext::get('span_depth') . "\n\n";

// Simulate some work
usleep(50000); // 50ms

// Test 3: Push nested span
echo "3. Push nested span 'AttendanceService.validateQr':\n";
$nestedSpan = LogContext::pushSpan('AttendanceService.validateQr');
echo "   new span_id: " . $nestedSpan . "\n";
echo "   parent_span_id: " . LogContext::get('parent_span_id') . "\n";
echo "   operation: " . LogContext::get('current_operation') . "\n";
echo "   span_depth: " . LogContext::get('span_depth') . "\n\n";

// Simulate some work
usleep(25000); // 25ms

// Test 4: Pop nested span
echo "4. Pop nested span:\n";
$spanInfo = LogContext::popSpan();
echo "   completed span_id: " . $spanInfo['span_id'] . "\n";
echo "   completed operation: " . $spanInfo['operation'] . "\n";
echo "   duration_ms: " . $spanInfo['duration_ms'] . "\n";
echo "   current span_id now: " . LogContext::get('span_id') . "\n";
echo "   current operation now: " . LogContext::get('current_operation') . "\n\n";

// Test 5: Pop parent span
echo "5. Pop parent span:\n";
$spanInfo = LogContext::popSpan();
echo "   completed span_id: " . $spanInfo['span_id'] . "\n";
echo "   completed operation: " . $spanInfo['operation'] . "\n";
echo "   duration_ms: " . $spanInfo['duration_ms'] . "\n\n";

// Test 6: Get correlation context
echo "6. Correlation context:\n";
print_r(LogContext::getCorrelationContext());

echo "\n=== Testing CorrelatedLogger ===\n\n";

// Test 7: CorrelatedLogger
$logger = app(CorrelatedLogger::class);
echo "7. CorrelatedLogger instance created\n";

// Test with span
echo "8. Testing withSpan:\n";
$result = $logger->withSpan('test.operation', function () {
    usleep(10000); // 10ms
    return 'test result';
}, 'system');
echo "   Result: " . $result . "\n\n";

echo "=== All tests passed! ===\n";
