<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Attendance;

class CleanupDuplicateAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:cleanup-duplicates 
                            {--school= : Filter by specific school_id}
                            {--dry-run : Preview changes without executing}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up duplicate attendance records by soft deleting newer duplicates (keeps oldest record)';

    /**
     * Statistics tracking
     */
    protected int $totalDuplicateGroups = 0;
    protected int $totalRecordsDeleted = 0;
    protected int $totalRecordsKept = 0;
    protected array $cleanupLog = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $schoolId = $this->option('school');

        $this->info('🧹 Duplicate Attendance Cleanup Script');
        $this->newLine();

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Step 1: Identify duplicates
        $this->info('Step 1: Identifying duplicate records...');
        $duplicates = $this->identifyDuplicates($schoolId);

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicate attendance records found!');
            return Command::SUCCESS;
        }

        $this->totalDuplicateGroups = $duplicates->count();
        $totalRecords = $duplicates->sum('duplicate_count');
        $recordsToDelete = $totalRecords - $this->totalDuplicateGroups;

        $this->error("❌ Found {$this->totalDuplicateGroups} duplicate groups");
        $this->warn("📊 Total records: {$totalRecords}");
        $this->warn("📊 Records to keep: {$this->totalDuplicateGroups}");
        $this->warn("📊 Records to delete: {$recordsToDelete}");
        $this->newLine();

        // Step 2: Display breakdown
        $this->displayBreakdown($duplicates);

        // Step 3: Confirm action (unless --force or --dry-run)
        if (!$isDryRun && !$this->option('force')) {
            if (!$this->confirm('Do you want to proceed with cleanup?')) {
                $this->info('Cleanup cancelled.');
                return Command::SUCCESS;
            }
            $this->newLine();
        }

        // Step 4: Execute cleanup
        $this->info('Step 2: Executing cleanup...');
        $this->newLine();

        DB::beginTransaction();

        try {
            foreach ($duplicates as $duplicate) {
                $this->cleanupDuplicateGroup($duplicate, $isDryRun);
            }

            if (!$isDryRun) {
                DB::commit();
                $this->info('✅ Transaction committed successfully');
            } else {
                DB::rollBack();
                $this->info('🔍 Dry run completed - no changes made');
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ Error during cleanup: ' . $e->getMessage());
            Log::error('Duplicate attendance cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }

        // Step 5: Display summary
        $this->displaySummary($isDryRun);

        // Step 6: Save cleanup log
        if (!$isDryRun) {
            $this->saveCleanupLog();
        }

        return Command::SUCCESS;
    }

    /**
     * Identify duplicate attendance records
     */
    protected function identifyDuplicates(?string $schoolId)
    {
        $query = DB::table('attendances')
            ->select(
                'student_id',
                'schedule_id',
                'attendance_date',
                'school_id',
                DB::raw('COUNT(*) as duplicate_count'),
                DB::raw('GROUP_CONCAT(id ORDER BY created_at ASC) as record_ids'),
                DB::raw('MIN(created_at) as oldest_created_at'),
                DB::raw('MAX(created_at) as newest_created_at')
            )
            ->whereNull('deleted_at')
            ->groupBy('student_id', 'schedule_id', 'attendance_date', 'school_id')
            ->having('duplicate_count', '>', 1);

        if ($schoolId) {
            $query->where('school_id', $schoolId);
            $this->info("Filtering by school_id: {$schoolId}");
            $this->newLine();
        }

        return $query->get();
    }

    /**
     * Display breakdown by school
     */
    protected function displayBreakdown($duplicates): void
    {
        $this->info('📋 Breakdown by School:');
        $this->newLine();

        $bySchool = $duplicates->groupBy('school_id');

        $tableData = [];
        foreach ($bySchool as $schoolId => $schoolDuplicates) {
            $totalRecords = $schoolDuplicates->sum('duplicate_count');
            $duplicateGroups = $schoolDuplicates->count();
            $recordsToDelete = $totalRecords - $duplicateGroups;

            $tableData[] = [
                $schoolId,
                $duplicateGroups,
                $totalRecords,
                $duplicateGroups,
                $recordsToDelete,
            ];
        }

        $this->table(
            ['School ID', 'Duplicate Groups', 'Total Records', 'Keep', 'Delete'],
            $tableData
        );

        $this->newLine();
    }

    /**
     * Clean up a single duplicate group
     */
    protected function cleanupDuplicateGroup($duplicate, bool $isDryRun): void
    {
        $recordIds = array_map('intval', explode(',', $duplicate->record_ids));
        $keepId = $recordIds[0]; // Keep oldest
        $deleteIds = array_slice($recordIds, 1); // Delete rest

        // Fetch full records for logging
        $keepRecord = Attendance::find($keepId);
        $deleteRecords = Attendance::whereIn('id', $deleteIds)->get();

        // Log the action
        $logEntry = [
            'student_id' => $duplicate->student_id,
            'schedule_id' => $duplicate->schedule_id,
            'attendance_date' => $duplicate->attendance_date,
            'school_id' => $duplicate->school_id,
            'kept_id' => $keepId,
            'kept_created_at' => $duplicate->oldest_created_at,
            'deleted_ids' => $deleteIds,
            'deleted_count' => count($deleteIds),
            'timestamp' => now()->toDateTimeString(),
        ];

        $this->cleanupLog[] = $logEntry;

        // Display progress
        $this->line("Processing: Student {$duplicate->student_id}, Schedule {$duplicate->schedule_id}, Date {$duplicate->attendance_date}");
        $this->line("  ✅ Keeping: ID {$keepId} (created: {$duplicate->oldest_created_at})");
        $this->line("  ❌ Deleting: " . count($deleteIds) . " duplicate(s) - IDs: " . implode(', ', $deleteIds));

        // Execute soft delete (unless dry run)
        if (!$isDryRun) {
            foreach ($deleteRecords as $record) {
                $record->delete(); // Soft delete
                
                // Log to Laravel log
                Log::info('Duplicate attendance record deleted', [
                    'id' => $record->id,
                    'student_id' => $record->student_id,
                    'schedule_id' => $record->schedule_id,
                    'attendance_date' => $record->attendance_date,
                    'school_id' => $record->school_id,
                    'kept_id' => $keepId,
                    'reason' => 'duplicate_cleanup',
                ]);
            }

            $this->totalRecordsDeleted += count($deleteIds);
            $this->totalRecordsKept++;
        }

        $this->newLine();
    }

    /**
     * Display cleanup summary
     */
    protected function displaySummary(bool $isDryRun): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('           CLEANUP SUMMARY');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN - No changes were made');
            $this->newLine();
            $this->line("Would have processed: {$this->totalDuplicateGroups} duplicate groups");
            $this->line("Would have kept: {$this->totalDuplicateGroups} records");
            $this->line("Would have deleted: " . count(array_merge(...array_column($this->cleanupLog, 'deleted_ids'))) . " records");
        } else {
            $this->info('✅ Cleanup completed successfully!');
            $this->newLine();
            $this->line("Duplicate groups processed: {$this->totalDuplicateGroups}");
            $this->line("Records kept: {$this->totalRecordsKept}");
            $this->line("Records deleted: {$this->totalRecordsDeleted}");
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        if (!$isDryRun) {
            $this->info('📝 Cleanup log saved to: storage/logs/duplicate-cleanup-' . date('Y-m-d-His') . '.json');
            $this->newLine();
            $this->warn('⚠️  Next steps:');
            $this->line('1. Verify data integrity');
            $this->line('2. Run: php artisan attendance:identify-duplicates (should show 0 duplicates)');
            $this->line('3. Add unique constraint migration');
            $this->line('4. Update application code to use firstOrCreate');
            $this->newLine();
        }
    }

    /**
     * Save cleanup log to file
     */
    protected function saveCleanupLog(): void
    {
        $filename = storage_path('logs/duplicate-cleanup-' . date('Y-m-d-His') . '.json');
        
        $logData = [
            'executed_at' => now()->toDateTimeString(),
            'executed_by' => 'artisan:attendance:cleanup-duplicates',
            'summary' => [
                'duplicate_groups_processed' => $this->totalDuplicateGroups,
                'records_kept' => $this->totalRecordsKept,
                'records_deleted' => $this->totalRecordsDeleted,
            ],
            'details' => $this->cleanupLog,
        ];

        file_put_contents($filename, json_encode($logData, JSON_PRETTY_PRINT));

        Log::info('Duplicate attendance cleanup completed', [
            'summary' => $logData['summary'],
            'log_file' => $filename,
        ]);
    }
}
