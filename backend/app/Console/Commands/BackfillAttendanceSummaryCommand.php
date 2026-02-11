<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfills attendance_summary_views from the attendances table.
 *
 * Safe to run multiple times (upsert-based).
 * Run this during Phase 2 before switching to read model.
 *
 * Usage:
 *   php artisan cqrs:backfill-summaries
 *   php artisan cqrs:backfill-summaries --school=5
 *   php artisan cqrs:backfill-summaries --since=2026-01-01
 */
class BackfillAttendanceSummaryCommand extends Command
{
    protected $signature = 'cqrs:backfill-summaries
        {--school= : Backfill only a specific school_id}
        {--since= : Only backfill from this date (Y-m-d), default 90 days ago}
        {--chunk=500 : Number of rows per batch}
        {--dry-run : Show what would be backfilled without writing}';

    protected $description = 'Backfill attendance_summary_views from the attendances table (idempotent, safe for production)';

    public function handle(): int
    {
        if (! Schema::hasTable('attendance_summary_views')) {
            $this->error('Table attendance_summary_views does not exist. Run migrations first.');
            return self::FAILURE;
        }

        $since = $this->option('since')
            ? $this->option('since')
            : now()->subDays(90)->format('Y-m-d');

        $schoolId = $this->option('school') ? (int) $this->option('school') : null;
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Backfilling attendance_summary_views since {$since}...");
        if ($schoolId) {
            $this->info("Filtering: school_id = {$schoolId}");
        }
        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written.');
        }

        // Build the aggregated source query
        $query = DB::table('attendances')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->where('attendances.attendance_date', '>=', $since)
            ->when($schoolId, fn ($q) => $q->where('attendances.school_id', $schoolId))
            ->groupBy([
                'attendances.school_id',
                'schedules.class_id',
                'attendances.schedule_id',
                DB::raw('DATE(attendances.attendance_date)'),
            ])
            ->selectRaw("
                attendances.school_id,
                schedules.class_id,
                attendances.schedule_id,
                DATE(attendances.attendance_date) as attendance_date,
                COUNT(*) as total_students,
                SUM(CASE WHEN attendances.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN attendances.status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN attendances.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                SUM(CASE WHEN attendances.status IN ('sick','excused','permit') THEN 1 ELSE 0 END) as excused_count
            ");

        $totalRows = 0;
        $totalUpserted = 0;

        // Process in chunks to avoid memory issues
        $query->orderBy('attendances.school_id')
            ->orderBy(DB::raw('DATE(attendances.attendance_date)'))
            ->chunk($chunkSize, function ($rows) use ($dryRun, &$totalRows, &$totalUpserted) {
                $totalRows += $rows->count();

                if ($dryRun) {
                    $this->line("  Would upsert {$rows->count()} summary rows...");
                    return;
                }

                foreach ($rows as $row) {
                    $total = (int) $row->total_students;
                    $present = (int) $row->present_count;
                    $late = (int) $row->late_count;
                    $rate = $total > 0 ? round(($present + $late) / $total * 100, 2) : 0;

                    DB::table('attendance_summary_views')->upsert(
                        [
                            'school_id' => $row->school_id,
                            'class_id' => $row->class_id,
                            'schedule_id' => $row->schedule_id,
                            'attendance_date' => $row->attendance_date,
                            'total_students' => $total,
                            'present_count' => $present,
                            'late_count' => $late,
                            'absent_count' => (int) $row->absent_count,
                            'excused_count' => (int) $row->excused_count,
                            'attendance_rate' => $rate,
                            'updated_at' => now(),
                        ],
                        ['school_id', 'class_id', 'schedule_id', 'attendance_date'],
                        [
                            'total_students',
                            'present_count',
                            'late_count',
                            'absent_count',
                            'excused_count',
                            'attendance_rate',
                            'updated_at',
                        ]
                    );

                    $totalUpserted++;
                }

                $this->line("  Processed {$totalRows} source groups, upserted {$totalUpserted} summaries...");
            });

        if ($dryRun) {
            $this->info("DRY RUN complete. Would have processed {$totalRows} source groups.");
        } else {
            $this->info("Backfill complete. Upserted {$totalUpserted} summary rows from {$totalRows} source groups.");
        }

        return self::SUCCESS;
    }
}
