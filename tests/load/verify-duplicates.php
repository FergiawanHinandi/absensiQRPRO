<?php

/**
 * Duplicate Record Verification Script
 * 
 * Run this after load test to verify no duplicate attendance records were created.
 * 
 * Usage: php tests/load/verify-duplicates.php
 */

require __DIR__ . '/../../backend/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/../../backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=" . str_repeat("=", 79) . "\n";
echo "Duplicate Attendance Record Verification\n";
echo "=" . str_repeat("=", 79) . "\n\n";

$sessionId = 'session-2026-02-02-class-xa';

echo "Checking for duplicates in session: {$sessionId}\n\n";

// Check for duplicate attendance records
$duplicates = DB::select("
    SELECT 
        student_id,
        session_id,
        attendance_date,
        COUNT(*) as record_count
    FROM attendance_logs
    WHERE session_id = ?
    GROUP BY student_id, session_id, attendance_date
    HAVING COUNT(*) > 1
", [$sessionId]);

if (empty($duplicates)) {
    echo "✅ PASS: No duplicate records found!\n";
    echo "   All attendance records are unique.\n\n";
    $exitCode = 0;
} else {
    echo "❌ FAIL: Found " . count($duplicates) . " duplicate records:\n\n";
    
    foreach ($duplicates as $dup) {
        echo "   - Student: {$dup->student_id}\n";
        echo "     Date: {$dup->attendance_date}\n";
        echo "     Count: {$dup->record_count}\n\n";
    }
    $exitCode = 1;
}

// Get total records for this session
$totalRecords = DB::table('attendance_logs')
    ->where('session_id', $sessionId)
    ->count();

echo "Total attendance records: {$totalRecords}\n";

// Get unique students
$uniqueStudents = DB::table('attendance_logs')
    ->where('session_id', $sessionId)
    ->distinct('student_id')
    ->count('student_id');

echo "Unique students: {$uniqueStudents}\n";

// Calculate duplicate percentage
$duplicatePercentage = $totalRecords > 0 
    ? round((($totalRecords - $uniqueStudents) / $totalRecords) * 100, 2)
    : 0;

echo "Duplicate percentage: {$duplicatePercentage}%\n";

echo "\n" . str_repeat("=", 80) . "\n";

if ($duplicatePercentage > 0) {
    echo "⚠️  WARNING: Duplicate prevention mechanism needs review!\n";
} else {
    echo "✅ SUCCESS: Duplicate prevention working correctly!\n";
}

echo str_repeat("=", 80) . "\n";

exit($exitCode);
