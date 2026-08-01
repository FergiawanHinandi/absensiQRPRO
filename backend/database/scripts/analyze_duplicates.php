<?php

/**
 * Duplicate Attendance Analysis Script
 * 
 * This script provides detailed analysis of duplicate attendance records
 * Run: php backend/database/scripts/analyze_duplicates.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     Duplicate Attendance Records Analysis                      ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// 1. Find all duplicates
echo "🔍 Step 1: Identifying duplicate records...\n\n";

// Detect database driver
$driver = DB::connection()->getDriverName();

// Use appropriate aggregate function based on database driver
$concatIds = $driver === 'pgsql' 
    ? "string_agg(CAST(id AS TEXT), ',' ORDER BY created_at ASC) as ids"
    : 'GROUP_CONCAT(id ORDER BY created_at ASC) as ids';

$concatStates = $driver === 'pgsql'
    ? "string_agg(DISTINCT CAST(state AS TEXT), ',') as states"
    : 'GROUP_CONCAT(DISTINCT state ORDER BY state) as statuses';

$concatStatuses = $driver === 'pgsql'
    ? "string_agg(DISTINCT status, ',') as statuses"
    : 'GROUP_CONCAT(DISTINCT status ORDER BY status) as statuses';

$duplicates = DB::table('attendances')
    ->select(
        'student_id',
        'schedule_id',
        'attendance_date',
        'school_id',
        DB::raw('COUNT(*) as count'),
        DB::raw($concatIds),
        DB::raw('MIN(created_at) as first_created'),
        DB::raw('MAX(created_at) as last_created'),
        DB::raw($concatStates),
        DB::raw($concatStatuses)
    )
    ->whereNull('deleted_at')
    ->groupBy('student_id', 'schedule_id', 'attendance_date', 'school_id')
    ->havingRaw('COUNT(*) > 1')
    ->get();

if ($duplicates->isEmpty()) {
    echo "✅ No duplicates found! Database is clean.\n";
    exit(0);
}

$totalDuplicateGroups = $duplicates->count();
$totalRecords = $duplicates->sum('count');
$recordsToDelete = $totalRecords - $totalDuplicateGroups;

echo "📊 Summary:\n";
echo "   • Duplicate groups: {$totalDuplicateGroups}\n";
echo "   • Total duplicate records: {$totalRecords}\n";
echo "   • Records to keep: {$totalDuplicateGroups}\n";
echo "   • Records to delete: {$recordsToDelete}\n\n";

// 2. Breakdown by school
echo "🏫 Step 2: Breakdown by school...\n\n";

$bySchool = $duplicates->groupBy('school_id');

echo "┌─────────────┬──────────────────┬───────────────┬──────────────────┐\n";
echo "│ School ID   │ Duplicate Groups │ Total Records │ Records to Clean │\n";
echo "├─────────────┼──────────────────┼───────────────┼──────────────────┤\n";

foreach ($bySchool as $schoolId => $schoolDuplicates) {
    $groups = $schoolDuplicates->count();
    $total = $schoolDuplicates->sum('count');
    $toClean = $total - $groups;
    
    printf("│ %-11s │ %-16s │ %-13s │ %-16s │\n", 
        $schoolId, 
        $groups, 
        $total, 
        $toClean
    );
}

echo "└─────────────┴──────────────────┴───────────────┴──────────────────┘\n\n";

// 3. Analyze duplicate patterns
echo "🔬 Step 3: Analyzing duplicate patterns...\n\n";

$stateConflicts = 0;
$statusConflicts = 0;
$sameDay = 0;
$differentDays = 0;

foreach ($duplicates as $duplicate) {
    $states = explode(',', $duplicate->states);
    $statuses = explode(',', $duplicate->statuses);
    
    if (count(array_unique($states)) > 1) {
        $stateConflicts++;
    }
    
    if (count(array_unique($statuses)) > 1) {
        $statusConflicts++;
    }
    
    $firstCreated = strtotime($duplicate->first_created);
    $lastCreated = strtotime($duplicate->last_created);
    $daysDiff = ($lastCreated - $firstCreated) / 86400;
    
    if ($daysDiff < 1) {
        $sameDay++;
    } else {
        $differentDays++;
    }
}

echo "   Pattern Analysis:\n";
echo "   • Duplicates with conflicting states: {$stateConflicts}\n";
echo "   • Duplicates with conflicting statuses: {$statusConflicts}\n";
echo "   • Created on same day: {$sameDay}\n";
echo "   • Created on different days: {$differentDays}\n\n";

// 4. Show sample duplicates
echo "📋 Step 4: Sample duplicate records (first 10)...\n\n";

$samples = $duplicates->take(10);

foreach ($samples as $index => $duplicate) {
    $ids = explode(',', $duplicate->ids);
    $keepId = $ids[0];
    $deleteIds = array_slice($ids, 1);
    
    echo "Group " . ($index + 1) . ":\n";
    echo "  • Student ID: {$duplicate->student_id}\n";
    echo "  • Schedule ID: {$duplicate->schedule_id}\n";
    echo "  • Date: {$duplicate->attendance_date}\n";
    echo "  • School ID: {$duplicate->school_id}\n";
    echo "  • Count: {$duplicate->count}\n";
    echo "  • States: {$duplicate->states}\n";
    echo "  • Statuses: {$duplicate->statuses}\n";
    echo "  • ✅ Keep: ID {$keepId} (created: {$duplicate->first_created})\n";
    echo "  • ❌ Delete: IDs " . implode(', ', $deleteIds) . "\n";
    echo "\n";
}

if ($totalDuplicateGroups > 10) {
    echo "... and " . ($totalDuplicateGroups - 10) . " more duplicate groups\n\n";
}

// 5. Generate cleanup SQL
echo "🔧 Step 5: Generating cleanup strategy...\n\n";

echo "Recommended approach:\n";
echo "1. Keep the OLDEST record (by created_at) for each duplicate group\n";
echo "2. Soft delete all newer duplicates\n";
echo "3. Log all deletions for audit trail\n\n";

// 6. Risk assessment
echo "⚠️  Step 6: Risk assessment...\n\n";

$highRisk = $duplicates->filter(function ($dup) {
    $states = explode(',', $dup->states);
    return count(array_unique($states)) > 1;
})->count();

$mediumRisk = $duplicates->filter(function ($dup) {
    $firstCreated = strtotime($dup->first_created);
    $lastCreated = strtotime($dup->last_created);
    $daysDiff = ($lastCreated - $firstCreated) / 86400;
    return $daysDiff > 7;
})->count();

$lowRisk = $totalDuplicateGroups - $highRisk - $mediumRisk;

echo "   Risk Levels:\n";
echo "   🔴 High Risk (conflicting states): {$highRisk}\n";
echo "   🟡 Medium Risk (created >7 days apart): {$mediumRisk}\n";
echo "   🟢 Low Risk (same state, created close together): {$lowRisk}\n\n";

// 7. Next steps
echo "📝 Next Steps:\n\n";
echo "1. Review the analysis above\n";
echo "2. Export detailed report:\n";
echo "   php artisan attendance:identify-duplicates --export=csv\n\n";
echo "3. Create cleanup migration:\n";
echo "   php artisan make:migration cleanup_duplicate_attendance_records\n\n";
echo "4. Test cleanup on staging environment first\n\n";
echo "5. Run cleanup migration:\n";
echo "   php artisan migrate\n\n";

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║     Analysis Complete                                          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
