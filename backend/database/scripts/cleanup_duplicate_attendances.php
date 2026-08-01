<?php

/**
 * Cleanup Duplicate Attendance Records
 * 
 * This script removes duplicate attendance records by:
 * 1. Keeping the oldest record (first created_at)
 * 2. Soft deleting all newer duplicates
 * 3. Logging all cleanup actions for audit trail
 * 
 * Usage: php database/scripts/cleanup_duplicate_attendances.php [--dry-run]
 * 
 * Options:
 *   --dry-run    Show what would be deleted without actually deleting
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Helpers\TimezoneHelper;

// Check for dry-run mode
$dryRun = in_array('--dry-run', $argv);

echo "=================================================================\n";
echo "Duplicate Attendance Records Cleanup\n";
echo "=================================================================\n";
if ($dryRun) {
    echo "🔍 DRY RUN MODE - No changes will be made\n";
}
echo "\n";

// Find duplicate records
$duplicates = DB::select("
    SELECT 
        student_id,
        schedule_id,
        attendance_date,
        school_id,
        COUNT(*) as duplicate_count,
        GROUP_CONCAT(id ORDER BY created_at ASC) as attendance_ids
    FROM attendances
    WHERE deleted_at IS NULL
    GROUP BY student_id, schedule_id, attendance_date, school_id
    HAVING COUNT(*) > 1
    ORDER BY school_id, attendance_date DESC
");

if (empty($duplicates)) {
    echo "✅ No duplicate attendance records found!\n";
    echo "The database is clean.\n\n";
    exit(0);
}

echo "Found " . count($duplicates) . " sets of duplicate records\n\n";

$totalToDelete = 0;
$deletedRecords = [];
$errors = [];

// Create audit log table if not exists
DB::statement("
    CREATE TABLE IF NOT EXISTS attendance_cleanup_log (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        attendance_id BIGINT UNSIGNED NOT NULL,
        student_id BIGINT UNSIGNED NOT NULL,
        schedule_id BIGINT UNSIGNED NOT NULL,
        attendance_date DATE NOT NULL,
        school_id BIGINT UNSIGNED NOT NULL,
        state VARCHAR(50),
        status VARCHAR(50),
        created_at TIMESTAMP,
        deleted_at TIMESTAMP,
        cleanup_reason VARCHAR(255),
        cleanup_performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cleanup_school (school_id),
        INDEX idx_cleanup_date (attendance_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "Processing duplicates...\n";
echo "-------------------------------------------------------------------\n";

DB::beginTransaction();

try {
    foreach ($duplicates as $i => $duplicate) {
        $ids = explode(',', $duplicate->attendance_ids);
        $keepId = $ids[0]; // Keep the oldest (first in list)
        $deleteIds = array_slice($ids, 1); // Delete the rest
        
        echo sprintf(
            "%d. Student %d, Schedule %d, Date %s, School %d\n",
            $i + 1,
            $duplicate->student_id,
            $duplicate->schedule_id,
            $duplicate->attendance_date,
            $duplicate->school_id
        );
        echo "   Keeping ID: {$keepId}\n";
        echo "   Deleting IDs: " . implode(', ', $deleteIds) . "\n";
        
        // Get details of records to delete for audit log
        $recordsToDelete = DB::table('attendances')
            ->whereIn('id', $deleteIds)
            ->get();
        
        foreach ($recordsToDelete as $record) {
            // Log to audit table
            if (!$dryRun) {
                DB::table('attendance_cleanup_log')->insert([
                    'attendance_id' => $record->id,
                    'student_id' => $record->student_id,
                    'schedule_id' => $record->schedule_id,
                    'attendance_date' => $record->attendance_date,
                    'school_id' => $record->school_id,
                    'state' => $record->state,
                    'status' => $record->status,
                    'created_at' => $record->created_at,
                    'deleted_at' => TimezoneHelper::now(),
                    'cleanup_reason' => 'Duplicate record - keeping oldest (ID: ' . $keepId . ')',
                    'cleanup_performed_at' => TimezoneHelper::now(),
                ]);
            }
            
            $deletedRecords[] = $record->id;
            $totalToDelete++;
        }
        
        // Soft delete duplicates
        if (!$dryRun) {
            DB::table('attendances')
                ->whereIn('id', $deleteIds)
                ->update([
                    'deleted_at' => TimezoneHelper::now(),
                ]);
        }
    }
    
    if ($dryRun) {
        echo "\n";
        echo "🔍 DRY RUN COMPLETE - No changes were made\n";
        echo "Total records that would be deleted: {$totalToDelete}\n";
        DB::rollBack();
    } else {
        DB::commit();
        echo "\n";
        echo "✅ CLEANUP COMPLETE\n";
        echo "Total records soft deleted: {$totalToDelete}\n";
        echo "Audit log entries created: {$totalToDelete}\n";
    }
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "\n";
    echo "❌ ERROR during cleanup: " . $e->getMessage() . "\n";
    echo "Transaction rolled back. No changes were made.\n";
    exit(1);
}

echo "\n";
echo "=================================================================\n";
echo "Cleanup Summary:\n";
echo "=================================================================\n";
echo "Duplicate sets processed: " . count($duplicates) . "\n";
echo "Records soft deleted: {$totalToDelete}\n";

if (!$dryRun) {
    echo "\n";
    echo "📋 Audit Log:\n";
    echo "All deleted records have been logged in 'attendance_cleanup_log' table\n";
    echo "You can review the cleanup with:\n";
    echo "  SELECT * FROM attendance_cleanup_log ORDER BY cleanup_performed_at DESC;\n";
    
    echo "\n";
    echo "🔄 Rollback Instructions (if needed):\n";
    echo "To restore deleted records:\n";
    echo "  UPDATE attendances SET deleted_at = NULL WHERE id IN (\n";
    echo "    SELECT attendance_id FROM attendance_cleanup_log\n";
    echo "    WHERE cleanup_performed_at >= '" . TimezoneHelper::now()->subHours(1)->toDateTimeString() . "'\n";
    echo "  );\n";
}

echo "\n";
echo "✅ Next Step: Run the migration to add unique constraint\n";
echo "   php artisan migrate\n\n";

exit(0);
