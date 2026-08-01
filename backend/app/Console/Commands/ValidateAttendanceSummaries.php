<?php

namespace App\Console\Commands;

use App\Services\AttendanceSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Validate Attendance Summaries Command
 * 
 * Validates that summary data matches actual attendance records.
 * Useful for detecting data drift or corruption.
 */
class ValidateAttendanceSummaries extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'attendance:validate-summaries
                            {--school= : School ID to validate (optional)}
                            {--date= : Specific date to validate (YYYY-MM-DD, default: today)}
                            {--fix : Automatically fix discrepancies}';

    /**
     * The console command description.
     */
    protected $description = 'Validate attendance summary accuracy against raw data';

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
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $this->info("Validating summaries for {$date->toDateString()}...");

        // Get summaries to validate
        $summaries = $this->getSummariesToValidate($date);

        if ($summaries->isEmpty()) {
            $this->warn('No summaries found to validate.');
            return self::SUCCESS;
        }

        $this->info('Validating ' . $summaries->count() . ' summaries...');

        $accurate = 0;
        $inaccurate = 0;
        $fixed = 0;

        $progressBar = $this->output->createProgressBar($summaries->count());
        $progressBar->start();

        foreach ($summaries as $summary) {
            $result = $this->summaryService->validateSummary(
                $summary->school_id,
                $summary->class_id,
                $summary->attendance_date
            );

            if ($result['accurate']) {
                $accurate++;
            } else {
                $inaccurate++;
                
                $this->newLine();
                $this->warn("Discrepancy found:");
                $this->line("  School: {$summary->school_id}, Class: {$summary->class_id}");
                
                foreach ($result['differences'] as $diff) {
                    $this->line("  - {$diff}");
                }

                // Auto-fix if requested
                if ($this->option('fix')) {
                    $this->summaryService->updateSummary(
                        $summary->school_id,
                        $summary->class_id,
                        $summary->attendance_date
                    );
                    $fixed++;
                    $this->info("  ✅ Fixed");
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Validation Results:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Accurate', $accurate],
                ['Inaccurate', $inaccurate],
                ['Fixed', $fixed],
            ]
        );

        if ($inaccurate > 0 && !$this->option('fix')) {
            $this->warn('Run with --fix to automatically correct discrepancies.');
        }

        return $inaccurate > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Get summaries to validate.
     */
    protected function getSummariesToValidate($date)
    {
        $query = \App\Models\AttendanceDailyClassSummary::where('attendance_date', $date->toDateString());

        if ($schoolId = $this->option('school')) {
            $query->where('school_id', $schoolId);
        }

        return $query->get();
    }
}

