<?php

/**
 * Identify Duplicate Attendance Records
 * 
 * This script identifies duplicate attendance records based on the unique constraint:
 * (student_id, schedule_id, attendance_date, school_id)
 * 
 * Usage: php database/scripts/identify_duplicate_attendances.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=================================================================\n";
echo "Duplicate Attendance Records Analysis\n";
echo "=================================================================\n\n";

// Find duplicate records
$duplicates = DB::select("
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id,
        COUNT(*) as duplicate_count,
        GROUP_CONCAT(id ORDER BY created_at ASC) as attendance_ids,
        MIN(created_at) as first_created,
        MAX(created_at) as last_created
    FROM attendances
    WHERE deleted_at IS NULL
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
    ORDER BY duplicate_count DESC, school_id, attendance_date DESC
");

if (empty($duplicates)) {
    echo "✅ No duplicate attendance records found!\n";
    echo "The database is clean and ready for unique constraint.\n\n";
    exit(0);
}

echo "⚠️  Found " . count($duplicates) . " sets of duplicate records\n\n";

$totalDuplicates = 0;
$duplicatesBySchool = [];
$duplicatesByDate = [];

foreach ($duplicates as $duplicate) {
    $totalDuplicates += ($duplicate->duplicate_count - 1); // -1 because we keep one
    
    // Group by school
    if (!isset($duplicatesBySchool[$duplicate->school_id])) {
        $duplicatesBySchool[$duplicate->school_id] = 0;
    }
    $duplicatesBySchool[$duplicate->school_id] += ($duplicate->duplicate_count - 1);
    
    // Group by date
    $date = substr($duplicate->attendance_date, 0, 10);
    if (!isset($duplicatesByDate[$date])) {
        $duplicatesByDate[$date] = 0;
    }
    $duplicatesByDate[$date] += ($duplicate->duplicate_count - 1);
}

echo "📊 Summary Statistics:\n";
echo "-------------------------------------------------------------------\n";
echo "Total duplicate sets: " . count($duplicates) . "\n";
echo "Total records to clean: " . $totalDuplicates . "\n";
echo "Schools affected: " . count($duplicatesBySchool) . "\n\n";

echo "📈 Duplicates by School:\n";
echo "-------------------------------------------------------------------\n";
arsort($duplicatesBySchool);
foreach (array_slice($duplicatesBySchool, 0, 10) as $schoolId => $count) {
    $school = DB::table('schools')->where('id', $schoolId)->first();
    $schoolName = $school ? $school->name : "Unknown (ID: $schoolId)";
    echo sprintf("  School %-40s: %d duplicates\n", $schoolName, $count);
}
if (count($duplicatesBySchool) > 10) {
    echo "  ... and " . (count($duplicatesBySchool) - 10) . " more schools\n";
}
echo "\n";

echo "📅 Duplicates by Date (Top 10):\n";
echo "-------------------------------------------------------------------\n";
arsort($duplicatesByDate);
foreach (array_slice($duplicatesByDate, 0, 10) as $date => $count) {
    echo sprintf("  %s: %d duplicates\n", $date, $count);
}
if (count($duplicatesByDate) > 10) {
    echo "  ... and " . (count($duplicatesByDate) - 10) . " more dates\n";
}
echo "\n";

echo "🔍 Sample Duplicate Records (First 5 sets):\n";
echo "-------------------------------------------------------------------\n";
foreach (array_slice($duplicates, 0, 5) as $i => $duplicate) {
    echo "\n" . ($i + 1) . ". Duplicate Set:\n";
    echo "   Student ID: {$duplicate->student_id}\n";
    echo "   Schedule ID: {$duplicate->schedule_id}\n";
    echo "   Date: {$duplicate->attendance_date}\n";
    echo "   School ID: {$duplicate->school_id}\n";
    echo "   Count: {$duplicate->duplicate_count} records\n";
    echo "   IDs: {$duplicate->attendance_ids}\n";
    echo "   First created: {$duplicate->first_created}\n";
    echo "   Last created: {$duplicate->last_created}\n";
    
    // Get details of each duplicate
    $ids = explode(',', $duplicate->attendance_ids);
    $records = DB::table('attendances')
        ->whereIn('id', $ids)
        ->orderBy('created_at', 'asc')
        ->get();
    
    echo "   Details:\n";
    foreach ($records as $j => $record) {
        $keepMarker = ($j === 0) ? " [KEEP]" : " [DELETE]";
        echo "     - ID {$record->id}{$keepMarker}: state={$record->state}, status={$record->status}, ";
        echo "created={$record->created_at}, check_in={$record->check_in_time}\n";
    }
}

if (count($duplicates) > 5) {
    echo "\n   ... and " . (count($duplicates) - 5) . " more duplicate sets\n";
}

echo "\n";
echo "=================================================================\n";
echo "Cleanup Strategy:\n";
echo "=================================================================\n";
echo "For each duplicate set, we will:\n";
echo "1. Keep the OLDEST record (first created_at)\n";
echo "2. Soft delete all newer duplicates\n";
echo "3. Log the cleanup action for audit trail\n";
echo "4. Total records to be soft deleted: {$totalDuplicates}\n\n";

echo "⚠️  IMPORTANT: Review these duplicates before running cleanup!\n";
echo "Run the cleanup script: php database/scripts/cleanup_duplicate_attendances.php\n\n";

// Export detailed report to CSV
$csvFile = storage_path('logs/duplicate_attendances_' . date('Y-m-d_His') . '.csv');
$fp = fopen($csvFile, 'w');

// CSV Header
fputcsv($fp, [
    'student_id',
    'schedule_id',
    'attendance_date',
    'school_id',
    'duplicate_count',
    'attendance_ids',
    'first_created',
    'last_created',
]);

// CSV Data
foreach ($duplicates as $duplicate) {
    fputcsv($fp, [
        $duplicate->student_id,
        $duplicate->schedule_id,
        $duplicate->attendance_date,
        $duplicate->school_id,
        $duplicate->duplicate_count,
        $duplicate->attendance_ids,
        $duplicate->first_created,
        $duplicate->last_created,
    ]);
}

fclose($fp);

echo "📄 Detailed report exported to: {$csvFile}\n\n";

exit(0);
