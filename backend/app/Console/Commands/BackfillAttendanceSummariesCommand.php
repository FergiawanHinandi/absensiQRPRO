<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\ReadModels\AttendanceDailySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill Attendance Summaries Command
 * 
 * Populates the attendance_daily_summaries table from existing attendance records.
 * Run this once after deploying CQRS architecture.
 * 
 * Usage:
 *   php artisan attendance:backfill-summaries
 *   php artisan attendance:backfill-summaries --school=1
 *   php artisan attendance:backfill-summaries --date=2026-02-01
 */
class BackfillAttendanceSummariesCommand extends Command
{
    protected $signature = 'attendance:backfill-summaries
                            {--school= : Specific school ID to backfill}
                            {--date= : Specific date to backfill (Y-m-d)}
                            {--from= : Start date for range (Y-m-d)}
                            {--to= : End date for range (Y-m-d)}
                            {--chunk=1000 : Chunk size for processing}';

    protected $description = 'Backfill attendance daily summaries from existing attendance records';

    public function handle()
    {
        $this->info('Starting attendance summaries backfill...');
        
        $schoolId = $this->option('school');
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        $chunkSize = (int) $this->option('chunk');
        
        // Build query
        $query = Attendance::query()
            ->select([
                'school_id',
                'class_id',
                'attendance_date',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as total_present'),
                DB::raw('SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) as total_late'),
                DB::raw('SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as total_absent'),
                DB::raw('SUM(CASE WHEN status = "excused" THEN 1 ELSE 0 END) as total_excused'),
            ])
            ->groupBy('school_id', 'class_id', 'attendance_date');
        
        if ($schoolId) {
            $query->where('school_id', $schoolId);
            $this->info("Filtering by school ID: {$schoolId}");
        }
        
        if ($date) {
            $query->whereDate('attendance_date', $date);
            $this->info("Filtering by date: {$date}");
        } elseif ($from && $to) {
            $query->whereBetween('attendance_date', [$from, $to]);
            $this->info("Filtering by date range: {$from} to {$to}");
        }
        
        $totalGroups = $query->count(DB::raw('DISTINCT CONCAT(school_id, "-", IFNULL(class_id, "null"), "-", attendance_date)'));
        $this->info("Found {$totalGroups} unique school-class-date combinations to process");
        
        $progressBar = $this->output->createProgressBar($totalGroups);
        $progressBar->start();
        
        $processed = 0;
        $created = 0;
        $updated = 0;
        
        // Process in chunks
        $query->chunk($chunkSize, function ($groups) use (&$processed, &$created, &$updated, $progressBar) {
            foreach ($groups as $group) {
                // School-wide summary (class_id = null)
                $this->upsertSummary(
                    schoolId: $group->school_id,
                    classId: null,
                    date: $group->attendance_date,
                    totalPresent: $group->total_present,
                    totalLate: $group->total_late,
                    totalAbsent: $group->total_absent,
                    totalExcused: $group->total_excused,
                    wasCreated: $wasCreated
                );
                
                if ($wasCreated) $created++; else $updated++;
                
                // Class-specific summary
                if ($group->class_id) {
                    $this->upsertSummary(
                        schoolId: $group->school_id,
                        classId: $group->class_id,
                        date: $group->attendance_date,
                        totalPresent: $group->total_present,
                        totalLate: $group->total_late,
                        totalAbsent: $group->total_absent,
                        totalExcused: $group->total_excused,
                        wasCreated: $wasCreated
                    );
                    
                    if ($wasCreated) $created++; else $updated++;
                }
                
                $processed++;
                $progressBar->advance();
            }
        });
        
        $progressBar->finish();
        $this->newLine(2);
        
        $this->info("✓ Backfill completed!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Groups Processed', $processed],
                ['Records Created', $created],
                ['Records Updated', $updated],
            ]
        );
        
        return Command::SUCCESS;
    }
    
    /**
     * Upsert a summary record
     */
    private function upsertSummary(
        int $schoolId,
        ?int $classId,
        string $date,
        int $totalPresent,
        int $totalLate,
        int $totalAbsent,
        int $totalExcused,
        bool &$wasCreated
    ): void {
        $total = $totalPresent + $totalLate + $totalAbsent + $totalExcused;
        $rate = $total > 0 
            ? round((($totalPresent + $totalLate) / $total) * 100, 2)
            : 0;
        
        $summary = AttendanceDailySummary::updateOrCreate(
            [
                'school_id' => $schoolId,
                'class_id' => $classId,
                'attendance_date' => $date,
            ],
            [
                'total_students' => $total,
                'total_present' => $totalPresent,
                'total_late' => $totalLate,
                'total_absent' => $totalAbsent,
                'total_excused' => $totalExcused,
                'attendance_rate' => $rate,
                'last_updated_at' => now(),
            ]
        );
        
        $wasCreated = $summary->wasRecentlyCreated;
    }
}
