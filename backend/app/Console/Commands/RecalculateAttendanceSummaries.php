<?php

namespace App\Console\Commands;

use App\Services\AttendanceSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recalculate Attendance Summaries Command
 * 
 * Backfills or recalculates attendance summaries for a date range.
 * Useful for:
 * - Initial data migration
 * - Fixing data inconsistencies
 * - Recovering from summary corruption
 */
class RecalculateAttendanceSummaries extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'attendance:recalculate-summaries
                            {--school= : School ID to recalculate (optional, all schools if omitted)}
                            {--date= : Specific date to recalculate (YYYY-MM-DD)}
                            {--from= : Start date for range (YYYY-MM-DD)}
                            {--to= : End date for range (YYYY-MM-DD)}
                            {--days=30 : Number of days to recalculate (default: 30)}';

    /**
     * The console command description.
     */
    protected $description = 'Recalculate attendance summaries for dashboard optimization';

    /**
     * Attendance Summary Service
     */
    protected AttendanceSummaryService $summaryService;

    /**
     * Constructor
     */
    public function __construct(AttendanceSummaryService $summaryService)
    {
        parent::__construct();
        $this->summaryService = $summaryService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting attendance summary recalculation...');

        // Determine date range
        [$startDate, $endDate] = $this->determineDateRange();

        $this->info("Date range: {$startDate->toDateString()} to {$endDate->toDateString()}");

        // Determine schools
        $schoolIds = $this->determineSchools();

        if (empty($schoolIds)) {
            $this->error('No schools found to process.');
            return self::FAILURE;
        }

        $this->info('Processing ' . count($schoolIds) . ' school(s)...');

        // Process each school
        $totalSummaries = 0;
        $progressBar = $this->output->createProgressBar(count($schoolIds));
        $progressBar->start();

        foreach ($schoolIds as $schoolId) {
            try {
                $count = $this->summaryService->updateSummariesForDateRange(
                    $schoolId,
                    $startDate,
                    $endDate
                );

                $totalSummaries += $count;
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to process school {$schoolId}: {$e->getMessage()}");
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Recalculation complete!");
        $this->info("Total summaries updated: {$totalSummaries}");

        return self::SUCCESS;
    }

    /**
     * Determine the date range to process.
     */
    protected function determineDateRange(): array
    {
        // Specific date
        if ($date = $this->option('date')) {
            $startDate = Carbon::parse($date);
            $endDate = $startDate->copy();
            return [$startDate, $endDate];
        }

        // Date range
        if ($from = $this->option('from')) {
            $startDate = Carbon::parse($from);
            $endDate = $this->option('to') 
                ? Carbon::parse($this->option('to'))
                : Carbon::today();
            return [$startDate, $endDate];
        }

        // Default: last N days
        $days = (int) $this->option('days');
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays($days);

        return [$startDate, $endDate];
    }

    /**
     * Determine which schools to process.
     */
    protected function determineSchools(): array
    {
        if ($schoolId = $this->option('school')) {
            return [(int) $schoolId];
        }

        // Get all schools
        return \App\Models\School::pluck('id')->toArray();
    }
}

