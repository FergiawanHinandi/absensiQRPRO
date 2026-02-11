#!/usr/bin/env php
<?php

/**
 * Cleanup Duplicate Attendance Records
 * 
 * This standalone script identifies and removes duplicate attendance records.
 * Strategy: Keep oldest record (lowest ID), soft delete duplicates.
 * 
 * Usage:
 *   php database/scripts/cleanup_duplicate_attendances.php [--dry-run] [--export]
 * 
 * Options:
 *   --dry-run    Preview changes without executing
 *   --export     Export cleanup report to JSON file
 */

// Load Laravel bootstrap
require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Parse command line arguments
$isDryRun = in_array('--dry-run', $argv);
$shouldExport = in_array('--export', $argv);

echo "===========================================\n";
echo "Duplicate Attendance Cleanup Script\n";
echo "===========================================\n\n";

if ($isDryRun) {
    echo "🔍 DRY RUN MODE - No changes will be made\n\n";
}

// Step 1: Find duplicates
echo "Step 1: Identifying duplicate records...\n";

$driver = DB::connection()->getDriverName();
$groupConcat = $driver === 'pgsql' 
    ? "STRING_AGG(CAST(id AS TEXT), ',' ORDER BY id)" 
    : "GROUP_CONCAT(id ORDER BY id)";

$duplicates = DB::select("
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id,
        COUNT(*) as duplicate_count,
        {$groupConcat} as attendance_ids,
        MIN(id) as keep_id,
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
    echo "The database is clean.\n\n";
    exit(0);
}

$totalSets = count($duplicates);
$totalRecordsToRemove = 0;
$duplicatesBySchool = [];

foreach ($duplicates as $duplicate) {
    $extraRecords = (int) $duplicate->duplicate_count - 1;
    $totalRecordsToRemove += $extraRecords;
    
    if (!isset($duplicatesBySchool[$duplicate->school_id])) {
        $duplicatesBySchool[$duplicate->school_id] = 0;
    }
    $duplicatesBySchool[$duplicate->school_id] += $extraRecords;
}

echo "⚠️  Found {$totalSets} sets of duplicate records\n";
echo "⚠️  Total records to be removed: {$totalRecordsToRemove}\n\n";

// Display summary table
echo str_pad("Set #", 8) . str_pad("School", 10) . str_pad("Student", 12) . 
     str_pad("Schedule", 12) . str_pad("Date", 14) . str_pad("Count", 8) . 
     str_pad("Keep ID", 10) . str_pad("Remove", 8) . "\n";
echo str_repeat("-", 82) . "\n";

foreach ($duplicates as $index => $duplicate) {
    $duplicateCount = (int) $duplicate->duplicate_count;
    $extraRecords = $duplicateCount - 1;
    
    echo str_pad($index + 1, 8) . 
         str_pad($duplicate->school_id, 10) . 
         str_pad($duplicate->student_id, 12) . 
         str_pad($duplicate->schedule_id, 12) . 
         str_pad($duplicate->attendance_date, 14) . 
         str_pad($duplicateCount, 8) . 
         str_pad($duplicate->keep_id, 10) . 
         str_pad($extraRecords, 8) . "\n";
}

echo "\n";

// Step 2: Confirmation (unless dry-run)
if (!$isDryRun) {
    echo "⚠️  WARNING: This will soft delete duplicate records!\n";
    echo "Strategy: Keep oldest record (lowest ID), soft delete others\n\n";
    echo "Do you want to proceed with cleanup? (yes/no): ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    $confirmation = trim(strtolower($line));
    fclose($handle);
    
    if ($confirmation !== 'yes' && $confirmation !== 'y') {
        echo "Cleanup cancelled by user.\n";
        exit(0);
    }
}

// Step 3: Cleanup duplicates
echo "\nStep 2: Cleaning up duplicates...\n";

$removedCount = 0;
$keptCount = 0;
$bySchool = [];
$removedIds = [];
$keptIds = [];

foreach ($duplicates as $duplicate) {
    $ids = explode(',', $duplicate->attendance_ids);
    $keepId = (int) $duplicate->keep_id;
    
    // IDs to remove (all except the oldest)
    $idsToRemove = array_filter($ids, fn($id) => (int) $id !== $keepId);
    
    if (!$isDryRun) {
        // Soft delete duplicates
        DB::table('attendances')
            ->whereIn('id', $idsToRemove)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
    }
    
    $removedCount += count($idsToRemove);
    $keptCount++;
    
    // Track by school
    if (!isset($bySchool[$duplicate->school_id])) {
        $bySchool[$duplicate->school_id] = 0;
    }
    $bySchool[$duplicate->school_id] += count($idsToRemove);
    
    // Track IDs for report
    $removedIds = array_merge($removedIds, array_map('intval', $idsToRemove));
    $keptIds[] = $keepId;
    
    echo ".";
}

echo "\n\n";

// Step 4: Display results
echo "===========================================\n";
echo "Cleanup Results:\n";
echo "===========================================\n";

if ($isDryRun) {
    echo "Would remove: {$removedCount} records\n";
} else {
    echo "✅ Successfully removed: {$removedCount} records\n";
    echo "✅ Kept: {$keptCount} records\n";
}

echo "\nCleanup by School:\n";
foreach ($bySchool as $schoolId => $count) {
    echo "  School ID {$schoolId}: {$count} record(s) removed\n";
}
echo "\n";

// Step 5: Export report
if ($shouldExport) {
    $report = [
        'removed_count' => $removedCount,
        'kept_count' => $keptCount,
        'by_school' => $bySchool,
        'removed_ids' => $removedIds,
        'kept_ids' => $keptIds,
        'timestamp' => now()->toDateTimeString(),
    ];
    
    $prefix = $isDryRun ? 'dry_run_' : '';
    $exportPath = storage_path("app/logs/{$prefix}duplicate_cleanup_" . date('Y-m-d_His') . '.json');
    
    if (!is_dir(dirname($exportPath))) {
        mkdir(dirname($exportPath), 0755, true);
    }
    
    file_put_contents($exportPath, json_encode($report, JSON_PRETTY_PRINT));
    echo "📄 Cleanup report exported to: {$exportPath}\n\n";
}

// Step 6: Log to application log
if (!$isDryRun) {
    Log::info('Duplicate attendance cleanup completed', [
        'removed_count' => $removedCount,
        'kept_count' => $keptCount,
        'by_school' => $bySchool,
        'timestamp' => now()->toDateTimeString(),
    ]);
}

// Step 7: Next steps
echo "===========================================\n";
echo "Next Steps:\n";
echo "===========================================\n";

if ($isDryRun) {
    echo "1. Review the changes above\n";
    echo "2. Run without --dry-run to apply changes:\n";
    echo "   php database/scripts/cleanup_duplicate_attendances.php\n";
} else {
    echo "1. ✅ Duplicates cleaned up\n";
    echo "2. Run: php artisan migrate (to apply unique constraint)\n";
    echo "3. Update application code to use firstOrCreate()\n";
    echo "4. Run: php artisan test --filter=AttendanceTest\n";
}
echo "\n";

exit(0);
