<?php

namespace App\Console\Commands;

use App\Services\AttendanceSummaryService;
use Illuminate\Console\Command;
use App\Models\School;

class CalculateAttendanceSummaries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:calculate-summaries 
                            {--school= : Calculate for specific school ID only}
                            {--date= : Calculate for specific date (YYYY-MM-DD), defaults to yesterday}
                            {--days= : Calculate for past N days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate daily attendance summaries for fast dashboard queries';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceSummaryService $summaryService): int
    {
        $schoolId = $this->option('school');
        $date = $this->option('date');
        $days = $this->option('days');

        // Determine date range
        if ($days) {
            $endDate = now()->subDay()->toDateString();
            $startDate = now()->subDays((int)$days)->toDateString();
        } else {
            $date = $date ?? now()->subDay()->toDateString();
            $startDate = $date;
            $endDate = $date;
        }

        // Determine schools to process
        $schools = $schoolId 
            ? School::where('id', $schoolId)->where('is_active', true)->get()
            : School::where('is_active', true)->get();

        if ($schools->isEmpty()) {
            $this->error('No active schools found.');
            return 1;
        }

        $this->info("Calculating attendance summaries...");
        $this->info("Date range: {$startDate} to {$endDate}");
        $this->info("Schools: " . $schools->count());

        $bar = $this->output->createProgressBar($schools->count());
        $bar->start();

        $totalCalculated = 0;

        foreach ($schools as $school) {
            try {
                $count = $summaryService->recalculateDateRange(
                    $school->id,
                    $startDate,
                    $endDate
                );

                $totalCalculated += $count;
                $bar->advance();

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed for school {$school->name} (ID: {$school->id}): " . $e->getMessage());
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Successfully calculated {$totalCalculated} daily summaries.");

        return 0;
    }
}
