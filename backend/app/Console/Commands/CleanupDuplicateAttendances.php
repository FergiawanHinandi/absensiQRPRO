<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupDuplicateAttendances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:cleanup-duplicates 
                            {--dry-run : Preview changes without executing}
                            {--export : Export cleanup report to JSON file}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup duplicate attendance records (keeps oldest, soft deletes duplicates)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('===========================================');
        $this->info('Duplicate Attendance Cleanup Script');
        $this->info('===========================================');
        $this->newLine();

        $isDryRun = $this->option('dry-run');
        $shouldExport = $this->option('export');
        $isForced = $this->option('force');

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Step 1: Find duplicates
        $this->info('Step 1: Identifying duplicate records...');
        $duplicates = $this->findDuplicates();

        if (empty($duplicates)) {
            $this->info('✅ No duplicate attendance records found!');
            $this->info('The database is clean.');
            $this->newLine();
            return self::SUCCESS;
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

        $this->warn("⚠️  Found {$totalSets} sets of duplicate records");
        $this->warn("⚠️  Total records to be removed: {$totalRecordsToRemove}");
        $this->newLine();

        // Display summary table
        $this->displaySummaryTable($duplicates);

        // Step 2: Confirmation (unless forced or dry-run)
        if (!$isDryRun && !$isForced) {
            $this->newLine();
            $this->warn('⚠️  WARNING: This will soft delete duplicate records!');
            $this->info('Strategy: Keep oldest record (lowest ID), soft delete others');
            $this->newLine();
            
            if (!$this->confirm('Do you want to proceed with cleanup?', false)) {
                $this->info('Cleanup cancelled by user.');
                return self::SUCCESS;
            }
        }

        // Step 3: Cleanup duplicates
        $this->newLine();
        $this->info('Step 2: Cleaning up duplicates...');
        
        $cleanupReport = $this->cleanupDuplicates($duplicates, $isDryRun);

        // Step 4: Display results
        $this->newLine();
        $this->info('===========================================');
        $this->info('Cleanup Results:');
        $this->info('===========================================');
        
        if ($isDryRun) {
            $this->info("Would remove: {$cleanupReport['removed_count']} records");
        } else {
            $this->info("✅ Successfully removed: {$cleanupReport['removed_count']} records");
            $this->info("✅ Kept: {$cleanupReport['kept_count']} records");
        }
        
        $this->newLine();
        $this->info('Cleanup by School:');
        foreach ($cleanupReport['by_school'] as $schoolId => $count) {
            $this->line("  School ID {$schoolId}: {$count} record(s) removed");
        }
        $this->newLine();

        // Step 5: Export report
        if ($shouldExport) {
            $exportPath = $this->exportReport($cleanupReport, $isDryRun);
            $this->info("📄 Cleanup report exported to: storage/app/{$exportPath}");
            $this->newLine();
        }

        // Step 6: Log to application log
        if (!$isDryRun) {
            Log::info('Duplicate attendance cleanup completed', [
                'removed_count' => $cleanupReport['removed_count'],
                'kept_count' => $cleanupReport['kept_count'],
                'by_school' => $cleanupReport['by_school'],
                'timestamp' => now()->toDateTimeString(),
            ]);
        }

        // Step 7: Next steps
        $this->info('===========================================');
        $this->info('Next Steps:');
        $this->info('===========================================');
        
        if ($isDryRun) {
            $this->line('1. Review the changes above');
            $this->line('2. Run without --dry-run to apply changes:');
            $this->line('   php artisan attendance:cleanup-duplicates');
        } else {
            $this->line('1. ✅ Duplicates cleaned up');
            $this->line('2. Run: php artisan migrate (to apply unique constraint)');
            $this->line('3. Update application code to use firstOrCreate()');
            $this->line('4. Run: php artisan test --filter=AttendanceTest');
        }
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Find duplicate attendance records
     */
    private function findDuplicates(): array
    {
        $driver = DB::connection()->getDriverName();
        $groupConcat = $driver === 'pgsql' 
            ? "STRING_AGG(CAST(id AS TEXT), ',' ORDER BY id)" 
            : "GROUP_CONCAT(id ORDER BY id)";

        return DB::select("
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
    }

    /**
     * Display summary table of duplicates
     */
    private function displaySummaryTable(array $duplicates): void
    {
        $tableData = [];
        foreach ($duplicates as $index => $duplicate) {
            $duplicateCount = (int) $duplicate->duplicate_count;
            $extraRecords = $duplicateCount - 1;
            
            $tableData[] = [
                'Set #' => $index + 1,
                'School ID' => $duplicate->school_id,
                'Student ID' => $duplicate->student_id,
                'Schedule ID' => $duplicate->schedule_id,
                'Date' => $duplicate->attendance_date,
                'Count' => $duplicateCount,
                'Keep ID' => $duplicate->keep_id,
                'To Remove' => $extraRecords,
            ];
        }

        $this->table(
            ['Set #', 'School ID', 'Student ID', 'Schedule ID', 'Date', 'Count', 'Keep ID', 'To Remove'],
            $tableData
        );
    }

    /**
     * Cleanup duplicate records
     */
    private function cleanupDuplicates(array $duplicates, bool $isDryRun): array
    {
        $removedCount = 0;
        $keptCount = 0;
        $bySchool = [];
        $removedIds = [];
        $keptIds = [];

        $progressBar = $this->output->createProgressBar(count($duplicates));
        $progressBar->start();

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
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return [
            'removed_count' => $removedCount,
            'kept_count' => $keptCount,
            'by_school' => $bySchool,
            'removed_ids' => $removedIds,
            'kept_ids' => $keptIds,
            'timestamp' => now()->toDateTimeString(),
        ];
    }

    /**
     * Export cleanup report to JSON
     */
    private function exportReport(array $report, bool $isDryRun): string
    {
        $prefix = $isDryRun ? 'dry_run_' : '';
        $exportPath = "logs/{$prefix}duplicate_cleanup_" . date('Y-m-d_His') . '.json';
        
        Storage::put($exportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        return $exportPath;
    }
}
