<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * Archive Attendance Command
 * 
 * Archives attendance data from main table to yearly archive tables
 * 
 * Usage:
 * php artisan attendance:archive 2024
 * php artisan attendance:archive 2024 --dry-run
 * php artisan attendance:archive 2024 --force
 */
class ArchiveAttendanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:archive 
                            {year : The year to archive (e.g., 2024)}
                            {--dry-run : Preview what would be archived without actually doing it}
                            {--force : Skip confirmation prompt}
                            {--chunk=1000 : Number of records to process at once}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive attendance records from main table to yearly archive table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $year = (int) $this->argument('year');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $chunkSize = (int) $this->option('chunk');
        
        // Validation
        if (!$this->validateYear($year)) {
            return Command::FAILURE;
        }

        $archiveTable = "attendances_{$year}";
        
        // Check if archive table exists
        if (!Schema::hasTable($archiveTable)) {
            $this->error("Archive table '{$archiveTable}' does not exist!");
            $this->info("Run migration first: php artisan migrate");
            return Command::FAILURE;
        }

        // Count records to archive
        $count = DB::table('attendances')
            ->whereYear('attendance_date', $year)
            ->count();

        if ($count === 0) {
            $this->info("No records found for year {$year}");
            return Command::SUCCESS;
        }

        $this->info("Found {$count} records to archive for year {$year}");

        // Dry run mode
        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
            $this->previewArchive($year);
            return Command::SUCCESS;
        }

        // Confirmation
        if (!$force) {
            if (!$this->confirm("Archive {$count} records from year {$year}?")) {
                $this->info("Archive cancelled");
                return Command::SUCCESS;
            }
        }

        // Start archiving
        $this->info("Starting archive process...");
        $startTime = microtime(true);

        try {
            DB::beginTransaction();

            // Archive in chunks
            $archived = $this->archiveInChunks($year, $archiveTable, $chunkSize);

            DB::commit();

            $duration = round(microtime(true) - $startTime, 2);
            
            $this->info("✅ Archive completed successfully!");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Records Archived', number_format($archived)],
                    ['Duration', "{$duration}s"],
                    ['Records/Second', number_format($archived / max($duration, 1))],
                    ['Archive Table', $archiveTable],
                ]
            );

            // Verify integrity
            $this->verifyIntegrity($year, $archiveTable, $archived);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->error("Archive failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            
            return Command::FAILURE;
        }
    }

    /**
     * Validate year input
     */
    protected function validateYear(int $year): bool
    {
        $currentYear = Carbon::now()->year;
        
        if ($year < 2020 || $year > $currentYear) {
            $this->error("Invalid year: {$year}");
            $this->info("Year must be between 2020 and {$currentYear}");
            return false;
        }

        if ($year === $currentYear) {
            $this->error("Cannot archive current year ({$currentYear})");
            $this->info("Only past years can be archived");
            return false;
        }

        return true;
    }

    /**
     * Preview what would be archived
     */
    protected function previewArchive(int $year): void
    {
        $stats = DB::table('attendances')
            ->whereYear('attendance_date', $year)
            ->selectRaw("
                COUNT(*) as total,
                COUNT(DISTINCT school_id) as schools,
                COUNT(DISTINCT student_id) as students,
                COUNT(DISTINCT class_id) as classes,
                MIN(attendance_date) as first_date,
                MAX(attendance_date) as last_date,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
            ")
            ->first();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Records', number_format($stats->total)],
                ['Schools', number_format($stats->schools)],
                ['Students', number_format($stats->students)],
                ['Classes', number_format($stats->classes)],
                ['Date Range', "{$stats->first_date} to {$stats->last_date}"],
                ['Present', number_format($stats->present)],
                ['Late', number_format($stats->late)],
                ['Absent', number_format($stats->absent)],
            ]
        );

        // Estimate size
        $estimatedSize = $stats->total * 500; // ~500 bytes per record
        $this->info("Estimated archive size: " . $this->formatBytes($estimatedSize));
    }

    /**
     * Archive records in chunks
     */
    protected function archiveInChunks(int $year, string $archiveTable, int $chunkSize): int
    {
        $totalArchived = 0;
        $bar = $this->output->createProgressBar();
        $bar->start();

        DB::table('attendances')
            ->whereYear('attendance_date', $year)
            ->orderBy('id')
            ->chunk($chunkSize, function ($records) use ($archiveTable, &$totalArchived, $bar) {
                // Convert to array for bulk insert
                $data = $records->map(function ($record) {
                    return (array) $record;
                })->toArray();

                // Insert into archive table
                DB::table($archiveTable)->insert($data);

                // Delete from main table
                $ids = $records->pluck('id')->toArray();
                DB::table('attendances')->whereIn('id', $ids)->delete();

                $totalArchived += count($records);
                $bar->advance(count($records));
            });

        $bar->finish();
        $this->newLine();

        return $totalArchived;
    }

    /**
     * Verify archive integrity
     */
    protected function verifyIntegrity(int $year, string $archiveTable, int $expectedCount): void
    {
        $this->info("Verifying integrity...");

        // Check archive table count
        $archiveCount = DB::table($archiveTable)->count();
        
        // Check main table (should be 0 for this year)
        $remainingCount = DB::table('attendances')
            ->whereYear('attendance_date', $year)
            ->count();

        if ($remainingCount > 0) {
            $this->warn("⚠️  Warning: {$remainingCount} records still in main table!");
        }

        if ($archiveCount !== $expectedCount) {
            $this->warn("⚠️  Warning: Archive count mismatch!");
            $this->warn("Expected: {$expectedCount}, Found: {$archiveCount}");
        } else {
            $this->info("✅ Integrity check passed");
        }

        // Check for data consistency
        $sampleCheck = DB::table($archiveTable)
            ->whereYear('attendance_date', $year)
            ->count();

        if ($sampleCheck !== $archiveCount) {
            $this->error("❌ Data consistency error: Year mismatch in archive!");
        } else {
            $this->info("✅ Data consistency verified");
        }
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
