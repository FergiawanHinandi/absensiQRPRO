<?php

/**
 * Query Existing Duplicate Attendances
 * 
 * This script identifies duplicate attendance records based on the combination:
 * (student_id, schedule_id, attendance_date, school_id)
 * 
 * Usage: php database/scripts/query_duplicate_attendances.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "===========================================\n";
echo "Duplicate Attendance Records Query\n";
echo "===========================================\n\n";

// Detect database driver
$driver = DB::connection()->getDriverName();
$groupConcat = $driver === 'pgsql' 
    ? "STRING_AGG(CAST(id AS TEXT), ',' ORDER BY id)" 
    : "GROUP_CONCAT(id ORDER BY id)";
$groupConcatStatus = $driver === 'pgsql'
    ? "STRING_AGG(status, ',' ORDER BY id)"
    : "GROUP_CONCAT(status ORDER BY id)";
$groupConcatDates = $driver === 'pgsql'
    ? "STRING_AGG(CAST(created_at AS TEXT), ',' ORDER BY id)"
    : "GROUP_CONCAT(created_at ORDER BY id)";

// Query to find duplicates
$duplicates = DB::select("
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id,
        COUNT(*) as duplicate_count,
        {$groupConcat} as attendance_ids,
        {$groupConcatStatus} as statuses,
        {$groupConcatDates} as created_dates
    FROM attendances
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
    ORDER BY duplicate_count DESC, school_id, attendance_date DESC
");

if (empty($duplicates)) {
    echo "✅ No duplicate attendance records found!\n";
    echo "The database is clean and ready for the unique constraint.\n\n";
    exit(0);
}

echo "⚠️  Found " . count($duplicates) . " sets of duplicate records:\n\n";

$totalDuplicateRecords = 0;
$duplicatesBySchool = [];

foreach ($duplicates as $index => $duplicate) {
    $duplicateCount = $duplicate->duplicate_count;
    $extraRecords = $duplicateCount - 1; // One record should remain
    $totalDuplicateRecords += $extraRecords;
    
    // Track by school
    if (!isset($duplicatesBySchool[$duplicate->school_id])) {
        $duplicatesBySchool[$duplicate->school_id] = 0;
    }
    $duplicatesBySchool[$duplicate->school_id] += $extraRecords;
    
    echo "Duplicate Set #" . ($index + 1) . ":\n";
    echo "  School ID: {$duplicate->school_id}\n";
    echo "  Student ID: {$duplicate->student_id}\n";
    echo "  Schedule ID: {$duplicate->schedule_id}\n";
    echo "  Date: {$duplicate->attendance_date}\n";
    echo "  Duplicate Count: {$duplicateCount} records\n";
    echo "  Attendance IDs: {$duplicate->attendance_ids}\n";
    echo "  Statuses: {$duplicate->statuses}\n";
    echo "  Created Dates: {$duplicate->created_dates}\n";
    echo "  → {$extraRecords} record(s) will be removed\n";
    echo "\n";
}

echo "===========================================\n";
echo "Summary:\n";
echo "===========================================\n";
echo "Total duplicate sets: " . count($duplicates) . "\n";
echo "Total records to be removed: {$totalDuplicateRecords}\n\n";

echo "Duplicates by School:\n";
foreach ($duplicatesBySchool as $schoolId => $count) {
    echo "  School ID {$schoolId}: {$count} duplicate(s)\n";
}

echo "\n===========================================\n";
echo "Detailed Analysis:\n";
echo "===========================================\n";

// Get more details about the duplicates
foreach ($duplicates as $duplicate) {
    $ids = explode(',', $duplicate->attendance_ids);
    
    echo "\nAnalyzing duplicate set for:\n";
    echo "  Student: {$duplicate->student_id}, Schedule: {$duplicate->schedule_id}, Date: {$duplicate->attendance_date}\n\n";
    
    $records = DB::table('attendances')
        ->whereIn('id', $ids)
        ->orderBy('id')
        ->get();
    
    foreach ($records as $record) {
        echo "  ID: {$record->id}\n";
        echo "    Status: {$record->status}\n";
        echo "    Check-in: " . ($record->check_in_time ?? 'NULL') . "\n";
        echo "    Check-out: " . ($record->check_out_time ?? 'NULL') . "\n";
        echo "    Manual: " . ($record->is_manual ? 'Yes' : 'No') . "\n";
        echo "    Recorded by: " . ($record->recorded_by ?? 'NULL') . "\n";
        echo "    Created: {$record->created_at}\n";
        echo "    Updated: {$record->updated_at}\n";
        echo "\n";
    }
}

echo "===========================================\n";
echo "Next Steps:\n";
echo "===========================================\n";
echo "1. Review the duplicate records above\n";
echo "2. Run the cleanup script to remove duplicates\n";
echo "3. Apply the unique constraint migration\n";
echo "4. Update application code to use firstOrCreate()\n\n";

// Export to JSON for further analysis
$exportPath = storage_path('logs/duplicate_attendances_' . date('Y-m-d_His') . '.json');
file_put_contents($exportPath, json_encode($duplicates, JSON_PRETTY_PRINT));
echo "📄 Detailed report exported to: {$exportPath}\n\n";

exit(0);
