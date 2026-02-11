<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QueryDuplicateAttendances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:query-duplicates 
                            {--export : Export results to JSON file}
                            {--detailed : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Query existing duplicate attendance records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('===========================================');
        $this->info('Duplicate Attendance Records Query');
        $this->info('===========================================');
        $this->newLine();

        // Detect database driver
        $driver = DB::connection()->getDriverName();
        $groupConcat = $driver === 'pgsql' 
            ? "STRING_AGG(CAST(id AS TEXT), ',' ORDER BY id)" 
            : "GROUP_CONCAT(id ORDER BY id)";
        $groupConcatStatus = $driver === 'pgsql'
            ? "STRING_AGG(status, ',' ORDER BY id)"
            : "GROUP_CONCAT(status ORDER BY id)";

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
                MIN(created_at) as first_created,
                MAX(created_at) as last_created
            FROM attendances
            GROUP BY student_id, schedule_id, attendance_date, school_id
            HAVING COUNT(*) > 1
            ORDER BY duplicate_count DESC, school_id, attendance_date DESC
        ");

        if (empty($duplicates)) {
            $this->info('✅ No duplicate attendance records found!');
            $this->info('The database is clean and ready for the unique constraint.');
            $this->newLine();
            return self::SUCCESS;
        }

        $this->warn('⚠️  Found ' . count($duplicates) . ' sets of duplicate records:');
        $this->newLine();

        $totalDuplicateRecords = 0;
        $duplicatesBySchool = [];

        // Display summary table
        $tableData = [];
        foreach ($duplicates as $index => $duplicate) {
            $duplicateCount = (int) $duplicate->duplicate_count;
            $extraRecords = $duplicateCount - 1;
            $totalDuplicateRecords += $extraRecords;
            
            // Track by school
            if (!isset($duplicatesBySchool[$duplicate->school_id])) {
                $duplicatesBySchool[$duplicate->school_id] = 0;
            }
            $duplicatesBySchool[$duplicate->school_id] += $extraRecords;
            
            $tableData[] = [
                'Set #' => $index + 1,
                'School ID' => $duplicate->school_id,
                'Student ID' => $duplicate->student_id,
                'Schedule ID' => $duplicate->schedule_id,
                'Date' => $duplicate->attendance_date,
                'Count' => $duplicateCount,
                'To Remove' => $extraRecords,
            ];
        }

        $this->table(
            ['Set #', 'School ID', 'Student ID', 'Schedule ID', 'Date', 'Count', 'To Remove'],
            $tableData
        );

        $this->newLine();
        $this->info('===========================================');
        $this->info('Summary:');
        $this->info('===========================================');
        $this->info("Total duplicate sets: " . count($duplicates));
        $this->info("Total records to be removed: {$totalDuplicateRecords}");
        $this->newLine();

        $this->info('Duplicates by School:');
        foreach ($duplicatesBySchool as $schoolId => $count) {
            $this->line("  School ID {$schoolId}: {$count} duplicate(s)");
        }
        $this->newLine();

        // Detailed mode: show detailed information
        if ($this->option('detailed')) {
            $this->info('===========================================');
            $this->info('Detailed Analysis:');
            $this->info('===========================================');
            $this->newLine();

            foreach ($duplicates as $duplicate) {
                $ids = explode(',', $duplicate->attendance_ids);
                
                $this->warn("Duplicate set for Student: {$duplicate->student_id}, Schedule: {$duplicate->schedule_id}, Date: {$duplicate->attendance_date}");
                
                $records = DB::table('attendances')
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->get();
                
                $detailTable = [];
                foreach ($records as $record) {
                    $detailTable[] = [
                        'ID' => $record->id,
                        'Status' => $record->status,
                        'Check-in' => $record->check_in_time ?? 'NULL',
                        'Manual' => $record->is_manual ? 'Yes' : 'No',
                        'Recorded By' => $record->recorded_by ?? 'NULL',
                        'Created' => $record->created_at,
                    ];
                }
                
                $this->table(
                    ['ID', 'Status', 'Check-in', 'Manual', 'Recorded By', 'Created'],
                    $detailTable
                );
                $this->newLine();
            }
        }

        // Export option
        if ($this->option('export')) {
            $exportPath = 'logs/duplicate_attendances_' . date('Y-m-d_His') . '.json';
            Storage::put($exportPath, json_encode($duplicates, JSON_PRETTY_PRINT));
            $this->info("📄 Detailed report exported to: storage/app/{$exportPath}");
            $this->newLine();
        }

        $this->info('===========================================');
        $this->info('Next Steps:');
        $this->info('===========================================');
        $this->line('1. Review the duplicate records above');
        $this->line('2. Run: php artisan attendance:cleanup-duplicates');
        $this->line('3. Run: php artisan migrate (to apply unique constraint)');
        $this->line('4. Update application code to use firstOrCreate()');
        $this->newLine();

        return self::SUCCESS;
    }
}
