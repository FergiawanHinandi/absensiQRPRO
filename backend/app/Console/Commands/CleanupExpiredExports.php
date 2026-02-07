<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to cleanup expired report exports
 *
 * This command should be scheduled to run hourly to:
 * - Delete expired export files from storage
 * - Remove expired export records from database
 */
class CleanupExpiredExports extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'exports:cleanup 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Force cleanup even if not scheduled}';

    /**
     * The console command description.
     */
    protected $description = 'Cleanup expired report exports (files and database records)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Starting export cleanup...');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        // Get expired exports
        $expired = ReportExport::expired()->get();

        if ($expired->isEmpty()) {
            $this->info('No expired exports found.');

            return self::SUCCESS;
        }

        $this->info("Found {$expired->count()} expired exports.");

        $deletedFiles = 0;
        $deletedRecords = 0;
        $errors = 0;

        $this->withProgressBar($expired, function ($export) use (&$deletedFiles, &$deletedRecords, &$errors, $isDryRun) {
            try {
                if ($isDryRun) {
                    // Just log what would happen
                    $this->newLine();
                    $this->line("  Would delete: {$export->file_name} ({$export->file_size_human})");
                } else {
                    // Actually delete
                    if ($export->file_path) {
                        $export->deleteFile();
                        $deletedFiles++;
                    }
                    $export->delete();
                    $deletedRecords++;
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to cleanup export', [
                    'export_id' => $export->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $this->newLine(2);

        if ($isDryRun) {
            $this->info("Would delete {$expired->count()} exports.");
        } else {
            $this->info("Deleted {$deletedFiles} files and {$deletedRecords} records.");

            if ($errors > 0) {
                $this->warn("Encountered {$errors} errors during cleanup. Check logs for details.");
            }

            Log::info('Export cleanup completed', [
                'deleted_files' => $deletedFiles,
                'deleted_records' => $deletedRecords,
                'errors' => $errors,
            ]);
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
