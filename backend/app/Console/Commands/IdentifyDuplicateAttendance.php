<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IdentifyDuplicateAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:identify-duplicates 
                            {--export= : Export results to file (csv or json)}
                            {--school= : Filter by specific school_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify duplicate attendance records based on (student_id, schedule_id, attendance_date, school_id)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Scanning for duplicate attendance records...');
        $this->newLine();

        // Detect database driver for appropriate aggregate function
        $driver = DB::connection()->getDriverName();
        $concatIds = $driver === 'pgsql' 
            ? "string_agg(CAST(id AS TEXT), ',' ORDER BY created_at ASC) as record_ids"
            : 'GROUP_CONCAT(id ORDER BY created_at ASC) as record_ids';

        // Build query to find duplicates
        $query = DB::table('attendances')
            ->select(
                'student_id',
                'schedule_id',
                'attendance_date',
                'school_id',
                DB::raw('COUNT(*) as duplicate_count'),
                DB::raw($concatIds),
                DB::raw('MIN(created_at) as oldest_created_at'),
                DB::raw('MAX(created_at) as newest_created_at')
            )
            ->whereNull('deleted_at')
            ->groupBy('student_id', 'schedule_id', 'attendance_date', 'school_id')
            ->havingRaw('COUNT(*) > 1');

        // Apply school filter if provided
        if ($schoolId = $this->option('school')) {
            $query->where('school_id', $schoolId);
            $this->info("Filtering by school_id: {$schoolId}");
        }

        $duplicates = $query->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicate attendance records found!');
            return Command::SUCCESS;
        }

        // Display summary
        $totalDuplicates = $duplicates->count();
        $totalRecords = $duplicates->sum('duplicate_count');
        $recordsToClean = $totalRecords - $totalDuplicates; // Keep oldest, remove rest

        $this->error("❌ Found {$totalDuplicates} duplicate groups affecting {$totalRecords} records");
        $this->warn("📊 Records to clean: {$recordsToClean}");
        $this->newLine();

        // Display detailed breakdown
        $this->displayDuplicateBreakdown($duplicates);

        // Export if requested
        if ($exportFormat = $this->option('export')) {
            $this->exportDuplicates($duplicates, $exportFormat);
        }

        // Display cleanup strategy
        $this->displayCleanupStrategy($duplicates);

        return Command::SUCCESS;
    }

    /**
     * Display duplicate breakdown by school
     */
    protected function displayDuplicateBreakdown($duplicates): void
    {
        $this->info('📋 Breakdown by School:');
        $this->newLine();

        $bySchool = $duplicates->groupBy('school_id');

        $tableData = [];
        foreach ($bySchool as $schoolId => $schoolDuplicates) {
            $totalRecords = $schoolDuplicates->sum('duplicate_count');
            $duplicateGroups = $schoolDuplicates->count();
            $recordsToClean = $totalRecords - $duplicateGroups;

            $tableData[] = [
                'school_id' => $schoolId,
                'duplicate_groups' => $duplicateGroups,
                'total_records' => $totalRecords,
                'records_to_clean' => $recordsToClean,
            ];
        }

        $this->table(
            ['School ID', 'Duplicate Groups', 'Total Records', 'Records to Clean'],
            array_map(fn($row) => [
                $row['school_id'],
                $row['duplicate_groups'],
                $row['total_records'],
                $row['records_to_clean'],
            ], $tableData)
        );

        $this->newLine();
    }

    /**
     * Display cleanup strategy
     */
    protected function displayCleanupStrategy($duplicates): void
    {
        $this->info('🔧 Recommended Cleanup Strategy:');
        $this->newLine();

        $this->line('1. Keep the OLDEST record (by created_at) for each duplicate group');
        $this->line('2. Soft delete all newer duplicate records');
        $this->line('3. Log all cleanup actions for audit trail');
        $this->newLine();

        // Show sample of records that would be kept vs deleted
        $this->info('📝 Sample Records (first 5 duplicate groups):');
        $this->newLine();

        $sampleDuplicates = $duplicates->take(5);

        foreach ($sampleDuplicates as $index => $duplicate) {
            $recordIds = explode(',', $duplicate->record_ids);
            $keepId = $recordIds[0]; // Oldest
            $deleteIds = array_slice($recordIds, 1);

            $this->line("Group " . ($index + 1) . ":");
            $this->line("  Student: {$duplicate->student_id}");
            $this->line("  Schedule: {$duplicate->schedule_id}");
            $this->line("  Date: {$duplicate->attendance_date}");
            $this->line("  School: {$duplicate->school_id}");
            $this->line("  ✅ Keep: ID {$keepId} (created: {$duplicate->oldest_created_at})");
            $this->line("  ❌ Delete: IDs " . implode(', ', $deleteIds));
            $this->newLine();
        }

        if ($duplicates->count() > 5) {
            $this->line("... and " . ($duplicates->count() - 5) . " more duplicate groups");
            $this->newLine();
        }

        $this->warn('⚠️  Run the cleanup migration to remove duplicates');
        $this->info('💡 Next step: php artisan make:migration cleanup_duplicate_attendance_records');
    }

    /**
     * Export duplicates to file
     */
    protected function exportDuplicates($duplicates, string $format): void
    {
        $filename = storage_path("app/duplicate-attendance-" . date('Y-m-d-His') . ".{$format}");

        if ($format === 'json') {
            file_put_contents($filename, $duplicates->toJson(JSON_PRETTY_PRINT));
        } elseif ($format === 'csv') {
            $fp = fopen($filename, 'w');
            
            // Header
            fputcsv($fp, [
                'student_id',
                'schedule_id',
                'attendance_date',
                'school_id',
                'duplicate_count',
                'record_ids',
                'oldest_created_at',
                'newest_created_at',
            ]);

            // Data
            foreach ($duplicates as $duplicate) {
                fputcsv($fp, [
                    $duplicate->student_id,
                    $duplicate->schedule_id,
                    $duplicate->attendance_date,
                    $duplicate->school_id,
                    $duplicate->duplicate_count,
                    $duplicate->record_ids,
                    $duplicate->oldest_created_at,
                    $duplicate->newest_created_at,
                ]);
            }

            fclose($fp);
        }

        $this->info("📁 Exported to: {$filename}");
        $this->newLine();
    }
}
